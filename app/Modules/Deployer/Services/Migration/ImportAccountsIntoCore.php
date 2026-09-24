<?php

namespace App\Modules\Deployer\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\UserIdentity;
use App\Core\Services\Migration\ReencryptLegacyValue;
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

    public function __construct(private readonly ReencryptLegacyValue $legacySecrets) {}

    /**
     * Inspect Deployer accounts without changing either database.
     *
     * @return array{accounts_seen:int,ready:int,already_mapped:int,needs_review:int,imported:int,review_records_created:int,mapped_social_identities_seen:int,mapped_social_identities_ready:int,mapped_social_identities_present:int,mapped_social_identities_needs_review:int,mapped_social_identities_imported:int,mapped_social_identity_review_records_created:int}
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

            if ($mapping?->status === 'reconciled') {
                $plan[] = [
                    'source_id' => $sourceId,
                    'account' => $account,
                    'canonical_id' => (string) $mapping->canonical_id,
                    'status' => 'already_mapped',
                    'reasons' => [],
                ];

                continue;
            }

            if ($mapping !== null && ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null)) {
                $plan[] = [
                    'source_id' => $sourceId,
                    'account' => $account,
                    'status' => 'needs_review',
                    'reasons' => ['existing_mapping_requires_review'],
                ];

                continue;
            }

            $reasons = [];
            $normalizedEmail = $this->normalizeEmail($account->email ?? null);
            $legacyTwoFactorSecret = in_array('two_factor_secret', $columns, true)
                ? ($account->two_factor_secret ?? null)
                : null;
            $coreTwoFactorSecret = $this->legacySecrets->forCore(
                'deployer',
                $legacyTwoFactorSecret === null ? null : (string) $legacyTwoFactorSecret,
            );
            $legacyRecoveryCodes = in_array('two_factor_recovery_codes', $columns, true)
                ? ($account->two_factor_recovery_codes ?? null)
                : null;
            $coreRecoveryCodes = $this->legacySecrets->forCore(
                'deployer',
                $legacyRecoveryCodes === null ? null : (string) $legacyRecoveryCodes,
            );

            if ($legacyTwoFactorSecret !== null && $legacyTwoFactorSecret !== '' && $coreTwoFactorSecret === null) {
                $reasons[] = 'two_factor_secret_requires_source_app_key';
            }

            if ($legacyRecoveryCodes !== null && $legacyRecoveryCodes !== '' && $coreRecoveryCodes === null) {
                $reasons[] = 'two_factor_recovery_codes_require_source_app_key';
            }

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
                'core_two_factor_secret' => $coreTwoFactorSecret,
                'core_two_factor_recovery_codes' => $coreRecoveryCodes,
                'status' => $reasons === [] ? 'ready' : 'needs_review',
                'reasons' => array_values(array_unique($reasons)),
            ];
        }

        $mappedSocialPlans = [];
        foreach ($plan as $index => $item) {
            if ($item['status'] === 'already_mapped') {
                $mappedSocialPlans[$index] = $this->planMappedSocialIdentities(
                    $item['account'],
                    $columns,
                    $item['canonical_id'],
                );
            }
        }

        $mappedSocialItems = array_merge([], ...array_values($mappedSocialPlans));
        $mappedSocialReport = [
            'mapped_social_identities_seen' => count($mappedSocialItems),
            'mapped_social_identities_ready' => count(array_filter($mappedSocialItems, fn (array $item): bool => $item['status'] === 'ready')),
            'mapped_social_identities_present' => count(array_filter($mappedSocialItems, fn (array $item): bool => $item['status'] === 'already_present')),
            'mapped_social_identities_needs_review' => count(array_filter($mappedSocialItems, fn (array $item): bool => $item['status'] === 'needs_review')),
            'mapped_social_identities_imported' => 0,
            'mapped_social_identity_review_records_created' => 0,
        ];

        $report = [
            'accounts_seen' => count($plan),
            'ready' => count(array_filter($plan, fn (array $item): bool => $item['status'] === 'ready')),
            'already_mapped' => count(array_filter($plan, fn (array $item): bool => $item['status'] === 'already_mapped')),
            'needs_review' => count(array_filter($plan, fn (array $item): bool => $item['status'] === 'needs_review')),
            'imported' => 0,
            'review_records_created' => 0,
            ...$mappedSocialReport,
        ];

        if (! $apply) {
            return $report;
        }

        foreach ($plan as $item) {
            if ($item['status'] === 'ready'
                && $this->importAccount(
                    $item['account'],
                    $columns,
                    $item['core_two_factor_secret'],
                    $item['core_two_factor_recovery_codes'],
                )) {
                $report['imported']++;
            } elseif ($item['status'] === 'needs_review'
                && $this->recordReview($item['source_id'], $item['reasons'])) {
                $report['review_records_created']++;
            }
        }

        $actualSocialOutcomes = [];
        foreach ($mappedSocialPlans as $items) {
            foreach ($items as $item) {
                $outcome = $item['status'] === 'needs_review'
                    ? 'needs_review'
                    : $this->importMappedSocialIdentity($item);

                if ($outcome === 'imported') {
                    $report['mapped_social_identities_imported']++;
                } elseif ($outcome === 'needs_review') {
                    $report['mapped_social_identity_review_records_created'] += (int) $this->recordSocialIdentityReview(
                        $item['provider'],
                        $item['provider_user_id'],
                        $item['reason'] ?? 'provider_identity_requires_review',
                    );
                }

                $actualSocialOutcomes[] = $outcome;
            }
        }

        $report['mapped_social_identities_present'] = count(array_filter(
            $actualSocialOutcomes,
            static fn (string $outcome): bool => $outcome === 'already_present',
        ));
        $report['mapped_social_identities_needs_review'] = count(array_filter(
            $actualSocialOutcomes,
            static fn (string $outcome): bool => $outcome === 'needs_review',
        ));

        return $report;
    }

    private function importAccount(
        object $source,
        array $columns,
        ?string $coreTwoFactorSecret,
        ?string $coreRecoveryCodes,
    ): bool {
        $sourceId = (string) $source->id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $columns, $coreTwoFactorSecret, $coreRecoveryCodes): bool {
            $existingMap = LegacyIdentityMap::query()
                ->where('source_product', 'deployer')
                ->where('source_entity', 'user')
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
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
                'two_factor_secret' => $coreTwoFactorSecret,
                'two_factor_recovery_codes' => $coreRecoveryCodes,
                'two_factor_confirmed_at' => $source->two_factor_confirmed_at ?? null,
                'preferences' => $source->preferences ?? null,
                'status' => 'active',
                'created_at' => $source->created_at ?? $now,
                'updated_at' => $source->updated_at ?? $now,
            ];

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

            $mapAttributes = [
                'canonical_entity' => 'user',
                'canonical_id' => $canonicalId,
                'status' => 'reconciled',
                'batch_key' => 'deployer-account-import-v1',
                'reconciliation_notes' => 'Imported as a distinct Core account after eligibility recheck; no email-based account merging was performed.',
                'metadata' => $this->reconciledMetadata($existingMap),
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($existingMap !== null) {
                $existingMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'deployer',
                    'source_entity' => 'user',
                    'source_id' => $sourceId,
                    ...$mapAttributes,
                ]);
            }

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function reconciledMetadata(?LegacyIdentityMap $existingMap): array
    {
        $metadata = $existingMap?->metadata ?? [];

        if (isset($metadata['reason_codes'])) {
            $metadata['review_history'][] = [
                'reason_codes' => $metadata['reason_codes'],
                'notes' => $existingMap?->reconciliation_notes,
                'recorded_at' => $existingMap?->created_at?->toISOString(),
            ];
            unset($metadata['reason_codes']);
        }

        $metadata['imported_by'] = 'platform:import-deployer-identities';

        return $metadata;
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
        $existingMap = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'user')
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if ($existingMap !== null) {
            if ($existingMap->status === 'needs_review') {
                $metadata = $existingMap->metadata ?? [];
                $metadata['reason_codes'] = $reasons;
                $existingMap->fill([
                    'reconciliation_notes' => implode('; ', $reasons),
                    'metadata' => $metadata,
                ])->save();
            }

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

    /** @return list<array{provider:string,provider_user_id:string,source_id:string,source:object,core_user_id:string,status:string,reason:?string}> */
    private function planMappedSocialIdentities(object $source, array $columns, string $coreUserId): array
    {
        $plan = [];

        foreach ($this->socialIdentities($source, $columns) as $identity) {
            $sourceId = $identity['provider'].':'.$identity['provider_user_id'];
            $existing = UserIdentity::query()
                ->where('provider', $identity['provider'])
                ->where('provider_user_id', $identity['provider_user_id'])
                ->first();
            $mapping = LegacyIdentityMap::query()
                ->where('source_product', 'deployer')
                ->where('source_entity', 'social_identity')
                ->where('source_id', $sourceId)
                ->first();

            if ($existing !== null && (string) $existing->user_id !== $coreUserId) {
                $status = 'needs_review';
                $reason = 'provider_identity_already_belongs_to_another_core_user';
            } elseif ($existing !== null && $mapping !== null
                && ($mapping->status !== 'reconciled' || (string) $mapping->canonical_id !== (string) $existing->id)) {
                $status = 'needs_review';
                $reason = 'social_identity_mapping_conflicts_with_existing_link';
            } elseif ($existing !== null) {
                $status = 'already_present';
                $reason = null;
            } elseif ($mapping !== null) {
                $status = 'needs_review';
                $reason = 'existing_social_identity_mapping_requires_review';
            } elseif (! DB::connection('core')->table('users')->where('id', $coreUserId)->exists()) {
                $status = 'needs_review';
                $reason = 'mapped_core_account_missing';
            } else {
                $status = 'ready';
                $reason = null;
            }

            $plan[] = [
                ...$identity,
                'source_id' => $sourceId,
                'source' => $source,
                'core_user_id' => $coreUserId,
                'status' => $status,
                'reason' => $reason,
            ];
        }

        return $plan;
    }

    /** @param array{provider:string,provider_user_id:string,source_id:string,source:object,core_user_id:string,status:string,reason:?string} $item */
    private function importMappedSocialIdentity(array $item): string
    {
        return DB::connection('core')->transaction(function () use ($item): string {
            $sourceUserMapping = LegacyIdentityMap::query()
                ->where('source_product', 'deployer')
                ->where('source_entity', 'user')
                ->where('source_id', (string) $item['source']->id)
                ->where('canonical_entity', 'user')
                ->where('canonical_id', $item['core_user_id'])
                ->where('status', 'reconciled')
                ->lockForUpdate()
                ->first();

            if ($sourceUserMapping === null) {
                $this->recordSocialIdentityReviewInsideTransaction(
                    $item['provider'],
                    $item['provider_user_id'],
                    'source_user_mapping_changed_before_link',
                );

                return 'needs_review';
            }

            if (! DB::connection('core')->table('users')->where('id', $item['core_user_id'])->exists()) {
                $this->recordSocialIdentityReviewInsideTransaction(
                    $item['provider'],
                    $item['provider_user_id'],
                    'mapped_core_account_missing',
                );

                return 'needs_review';
            }

            $existing = UserIdentity::query()
                ->where('provider', $item['provider'])
                ->where('provider_user_id', $item['provider_user_id'])
                ->first();

            if ($existing !== null) {
                if ((string) $existing->user_id !== $item['core_user_id']) {
                    $this->recordSocialIdentityReviewInsideTransaction(
                        $item['provider'],
                        $item['provider_user_id'],
                        'provider_identity_already_belongs_to_another_core_user',
                    );

                    return 'needs_review';
                }

                if (! $this->reconcileSocialIdentityMap($item, $existing)) {
                    $mapping = LegacyIdentityMap::query()
                        ->where('source_product', 'deployer')
                        ->where('source_entity', 'social_identity')
                        ->where('source_id', $item['source_id'])
                        ->first();

                    if ($mapping !== null && ($mapping->status !== 'reconciled' || (string) $mapping->canonical_id !== (string) $existing->id)) {
                        return 'needs_review';
                    }
                }

                return 'already_present';
            }

            $now = now();
            $inserted = DB::connection('core')->table('user_identities')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'user_id' => $item['core_user_id'],
                'provider' => $item['provider'],
                'provider_user_id' => $item['provider_user_id'],
                'provider_email' => $item['source']->email ?? null,
                'verified_at' => $item['source']->email_verified_at ?? null,
                'status' => 'active',
                'metadata' => json_encode(['migrated_from' => 'deployer'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $identity = UserIdentity::query()
                ->where('provider', $item['provider'])
                ->where('provider_user_id', $item['provider_user_id'])
                ->first();

            if ($identity === null || (string) $identity->user_id !== $item['core_user_id']) {
                $this->recordSocialIdentityReviewInsideTransaction(
                    $item['provider'],
                    $item['provider_user_id'],
                    'provider_identity_already_belongs_to_another_core_user',
                );

                return 'needs_review';
            }

            $this->reconcileSocialIdentityMap($item, $identity);

            return $inserted === 1 ? 'imported' : 'already_present';
        });
    }

    /** @param array{provider:string,provider_user_id:string,source_id:string,source:object,core_user_id:string,status:string,reason:?string} $item */
    private function reconcileSocialIdentityMap(array $item, UserIdentity $identity): bool
    {
        $mapping = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'social_identity')
            ->where('source_id', $item['source_id'])
            ->lockForUpdate()
            ->first();
        $now = now();
        $attributes = [
            'canonical_entity' => 'user_identity',
            'canonical_id' => (string) $identity->id,
            'status' => 'reconciled',
            'batch_key' => 'deployer-account-import-v1',
            'reconciliation_notes' => 'Linked to the provider identity on an already-reconciled Core account.',
            'metadata' => ['provider' => $item['provider'], 'linked_to_core_user' => $item['core_user_id']],
            'imported_at' => $now,
            'reconciled_at' => $now,
        ];

        if ($mapping !== null) {
            if ($mapping->status === 'reconciled' && (string) $mapping->canonical_id === (string) $identity->id) {
                return false;
            }

            if ($mapping->canonical_id !== null || $mapping->status !== 'needs_review') {
                return false;
            }

            $history = $mapping->metadata ?? [];
            if (isset($history['reason_codes'])) {
                $history['review_history'][] = [
                    'reason_codes' => $history['reason_codes'],
                    'notes' => $mapping->reconciliation_notes,
                    'recorded_at' => $mapping->created_at?->toISOString(),
                ];
                unset($history['reason_codes']);
            }
            $attributes['metadata'] = [...$history, ...$attributes['metadata']];
            $mapping->fill($attributes)->save();

            return true;
        }

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer',
            'source_entity' => 'social_identity',
            'source_id' => $item['source_id'],
            ...$attributes,
        ]);

        return true;
    }

    private function recordSocialIdentityReview(string $provider, string $providerUserId, string $reason): bool
    {
        return DB::connection('core')->transaction(
            fn (): bool => $this->recordSocialIdentityReviewInsideTransaction($provider, $providerUserId, $reason),
        );
    }

    private function recordSocialIdentityReviewInsideTransaction(string $provider, string $providerUserId, string $reason): bool
    {
        $sourceId = $provider.':'.$providerUserId;
        $mapping = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'social_identity')
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if ($mapping !== null) {
            if ($mapping->status !== 'needs_review') {
                return false;
            }

            $metadata = $mapping->metadata ?? [];
            $metadata['reason_codes'] = [$reason];
            $mapping->fill([
                'reconciliation_notes' => $reason,
                'metadata' => $metadata,
            ])->save();

            return false;
        }

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer',
            'source_entity' => 'social_identity',
            'source_id' => $sourceId,
            'canonical_entity' => 'user_identity',
            'canonical_id' => null,
            'status' => 'needs_review',
            'batch_key' => 'deployer-account-import-v1',
            'reconciliation_notes' => $reason,
            'metadata' => ['provider' => $provider, 'reason_codes' => [$reason]],
            'imported_at' => now(),
        ]);

        return true;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
