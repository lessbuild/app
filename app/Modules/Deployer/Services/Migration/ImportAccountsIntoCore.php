<?php

namespace App\Modules\Deployer\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\UserIdentity;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ImportAccountsIntoCore
{
    private const SOCIAL_PROVIDERS = [
        'github' => 'github_id',
        'gitlab' => 'gitlab_id',
        'bitbucket' => 'bitbucket_id',
    ];

    /**
     * Inspect Deployer accounts without changing either database.
     *
     * @return array{accounts_seen:int,ready:int,already_mapped:int,needs_review:int,imported:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        $columns = Schema::connection('deployer')->getColumnListing('users');
        $sourceAccounts = DB::connection('deployer')->table('users')->orderBy('id')->get();
        $sourceEmailCounts = $sourceAccounts
            ->map(fn (object $account): ?string => $this->normalizeEmail($account->email ?? null))
            ->filter()
            ->countBy();
        $identityMaps = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'user')
            ->get()
            ->keyBy(fn (LegacyIdentityMap $map): string => (string) $map->source_id);
        $coreEmails = DB::connection('core')->table('users')
            ->whereNotNull('email_normalized')
            ->pluck('email_normalized')
            ->mapWithKeys(fn ($email): array => [(string) $email => true]);
        $linkedProviderIds = UserIdentity::query()
            ->whereIn('provider', array_keys(self::SOCIAL_PROVIDERS))
            ->get(['provider', 'provider_user_id'])
            ->mapWithKeys(fn (UserIdentity $identity): array => [
                $identity->provider.'|'.$identity->provider_user_id => true,
            ]);

        $plan = [];
        foreach ($sourceAccounts as $account) {
            $sourceId = (string) $account->id;
            $mapping = $identityMaps->get($sourceId);

            if ($mapping !== null) {
                $plan[] = [
                    'source_id' => $sourceId,
                    'account' => $account,
                    'status' => $mapping->status === 'reconciled' ? 'already_mapped' : 'needs_review',
                    'reasons' => $mapping->status === 'reconciled' ? [] : ['existing_mapping_requires_review'],
                ];

                continue;
            }

            $reasons = [];
            $normalizedEmail = $this->normalizeEmail($account->email ?? null);

            if ($normalizedEmail !== null && ($sourceEmailCounts[$normalizedEmail] ?? 0) > 1) {
                $reasons[] = 'duplicate_source_email';
            }

            if ($normalizedEmail !== null && isset($coreEmails[$normalizedEmail])) {
                $reasons[] = 'email_matches_existing_core_account';
            }

            foreach (self::SOCIAL_PROVIDERS as $provider => $column) {
                $providerUserId = $account->{$column} ?? null;

                if (is_string($providerUserId) && trim($providerUserId) !== ''
                    && isset($linkedProviderIds[$provider.'|'.$providerUserId])) {
                    $reasons[] = 'social_identity_already_linked';
                }
            }

            $plan[] = [
                'source_id' => $sourceId,
                'account' => $account,
                'status' => $reasons === [] ? 'ready' : 'needs_review',
                'reasons' => array_values(array_unique($reasons)),
            ];
        }

        $report = [
            'accounts_seen' => count($plan),
            'ready' => count(array_filter($plan, fn (array $item): bool => $item['status'] === 'ready')),
            'already_mapped' => count(array_filter($plan, fn (array $item): bool => $item['status'] === 'already_mapped')),
            'needs_review' => count(array_filter($plan, fn (array $item): bool => $item['status'] === 'needs_review')),
            'imported' => 0,
            'review_records_created' => 0,
        ];

        if (! $apply) {
            return $report;
        }

        foreach ($plan as $item) {
            if ($item['status'] === 'ready'
                && $this->importAccount($item['account'], $columns)) {
                $report['imported']++;
            } elseif ($item['status'] === 'needs_review'
                && $this->recordReview($item['source_id'], $item['reasons'])) {
                $report['review_records_created']++;
            }
        }

        return $report;
    }

    private function importAccount(object $source, array $columns): bool
    {
        $sourceId = (string) $source->id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $columns): bool {
            $existingMap = LegacyIdentityMap::query()
                ->where('source_product', 'deployer')
                ->where('source_entity', 'user')
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($existingMap !== null) {
                return false;
            }

            $normalizedEmail = $this->normalizeEmail($source->email ?? null);

            if ($normalizedEmail !== null && DB::connection('core')->table('users')
                ->where('email_normalized', $normalizedEmail)->exists()) {
                return $this->recordReviewInsideTransaction($sourceId, ['email_matches_existing_core_account']);
            }

            $socialIdentities = $this->socialIdentities($source, $columns);
            foreach ($socialIdentities as $identity) {
                if (UserIdentity::query()
                    ->where('provider', $identity['provider'])
                    ->where('provider_user_id', $identity['provider_user_id'])
                    ->exists()) {
                    return $this->recordReviewInsideTransaction($sourceId, ['social_identity_already_linked']);
                }
            }

            $now = now();
            $canonicalId = (string) Str::ulid();
            $attributes = [
                'id' => $canonicalId,
                'name' => $source->name ?? null,
                'email' => $source->email ?? null,
                'email_normalized' => $normalizedEmail,
                'email_verified_at' => $source->email_verified_at ?? null,
                'password' => $source->password ?? null,
                'password_set_at' => $source->password_set_at ?? null,
                'auth_type' => $source->auth_type ?? null,
                'two_factor_secret' => $source->two_factor_secret ?? null,
                'two_factor_recovery_codes' => $source->two_factor_recovery_codes ?? null,
                'two_factor_confirmed_at' => $source->two_factor_confirmed_at ?? null,
                'preferences' => $source->preferences ?? null,
                'status' => 'active',
                'created_at' => $source->created_at ?? $now,
                'updated_at' => $source->updated_at ?? $now,
            ];

            // Raw ciphertext is intentionally copied unchanged. Re-encrypting here
            // could lock out an account if the legacy key is not the current app key.
            DB::connection('core')->table('users')->insert($attributes);

            foreach ($socialIdentities as $identity) {
                UserIdentity::query()->create([
                    'user_id' => $canonicalId,
                    'provider' => $identity['provider'],
                    'provider_user_id' => $identity['provider_user_id'],
                    'provider_email' => $source->email ?? null,
                    'verified_at' => $source->email_verified_at ?? null,
                    'status' => 'active',
                    'metadata' => ['migrated_from' => 'deployer'],
                ]);
            }

            LegacyIdentityMap::query()->create([
                'source_product' => 'deployer',
                'source_entity' => 'user',
                'source_id' => $sourceId,
                'canonical_entity' => 'user',
                'canonical_id' => $canonicalId,
                'status' => 'reconciled',
                'batch_key' => 'deployer-account-import-v1',
                'reconciliation_notes' => 'Imported as a distinct Core account; no email-based account merging was performed.',
                'metadata' => ['imported_by' => 'platform:import-deployer-identities'],
                'imported_at' => $now,
                'reconciled_at' => $now,
            ]);

            return true;
        });
    }

    /** @param list<string> $reasons */
    private function recordReview(string $sourceId, array $reasons): bool
    {
        return DB::connection('core')->transaction(
            fn (): bool => $this->recordReviewInsideTransaction($sourceId, $reasons),
        );
    }

    /** @param list<string> $reasons */
    private function recordReviewInsideTransaction(string $sourceId, array $reasons): bool
    {
        $alreadyRecorded = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'user')
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->exists();

        if ($alreadyRecorded) {
            return false;
        }

        $now = now();

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => null,
            'status' => 'needs_review',
            'batch_key' => 'deployer-account-import-v1',
            'reconciliation_notes' => implode('; ', $reasons),
            'metadata' => ['reason_codes' => $reasons],
            'imported_at' => $now,
        ]);

        return true;
    }

    /** @return list<array{provider:string,provider_user_id:string}> */
    private function socialIdentities(object $source, array $columns): array
    {
        $identities = [];

        foreach (self::SOCIAL_PROVIDERS as $provider => $column) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            $providerUserId = trim((string) ($source->{$column} ?? ''));

            if ($providerUserId !== '') {
                $identities[] = ['provider' => $provider, 'provider_user_id' => $providerUserId];
            }
        }

        return $identities;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
