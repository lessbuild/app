<?php

namespace App\Core\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class ImportAnalyticsAccountsIntoCore
{
    private const BATCH_KEY = 'analytics-account-import-v1';

    public function __construct(private readonly ReencryptLegacyValue $legacySecrets) {}

    /**
     * Inspect Analytics accounts and passkeys without changing either database.
     *
     * @return array{accounts_seen:int,ready:int,already_mapped:int,needs_review:int,imported:int,review_records_created:int,passkeys_seen:int,passkeys_imported:int}
     */
    public function run(bool $apply = false): array
    {
        if (! Schema::connection('analytics')->hasTable('users')) {
            throw new RuntimeException('The Analytics users table is unavailable on the analytics connection.');
        }

        $columns = Schema::connection('analytics')->getColumnListing('users');
        $sourceAccounts = DB::connection('analytics')->table('users')->orderBy('id')->get();
        $sourceIds = $sourceAccounts->map(fn (object $account): string => (string) $account->id);
        $sourceEmailCounts = $sourceAccounts
            ->map(fn (object $account): ?string => $this->normalizeEmail($account->email ?? null))
            ->filter()
            ->countBy();
        $identityMaps = LegacyIdentityMap::query()
            ->where('source_product', 'analytics')
            ->where('source_entity', 'user')
            ->get()
            ->keyBy(fn (LegacyIdentityMap $map): string => (string) $map->source_id);
        $coreEmails = DB::connection('core')->table('users')
            ->whereNotNull('email_normalized')
            ->pluck('email_normalized')
            ->mapWithKeys(fn ($email): array => [(string) $email => true]);

        $sourcePasskeys = collect();
        $coreCredentialIds = collect();

        if (Schema::connection('analytics')->hasTable('passkeys')) {
            if (! Schema::connection('core')->hasTable('passkeys')) {
                throw new RuntimeException('Run the Core platform migration before importing Analytics passkeys.');
            }

            $sourcePasskeys = DB::connection('analytics')->table('passkeys')
                ->whereIn('user_id', $sourceIds)
                ->orderBy('id')
                ->get()
                ->groupBy(fn (object $passkey): string => (string) $passkey->user_id);
            $coreCredentialIds = DB::connection('core')->table('passkeys')
                ->pluck('credential_id')
                ->mapWithKeys(fn ($credentialId): array => [(string) $credentialId => true]);
        }

        $plan = [];

        foreach ($sourceAccounts as $account) {
            $sourceId = (string) $account->id;
            $mapping = $identityMaps->get($sourceId);

            if ($mapping?->status === 'reconciled') {
                $plan[] = [
                    'source_id' => $sourceId,
                    'account' => $account,
                    'passkeys' => [],
                    'core_two_factor_secret' => null,
                    'core_two_factor_recovery_codes' => null,
                    'status' => 'already_mapped',
                    'reasons' => [],
                ];

                continue;
            }

            if ($mapping !== null && ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null)) {
                $plan[] = [
                    'source_id' => $sourceId,
                    'account' => $account,
                    'passkeys' => [],
                    'core_two_factor_secret' => null,
                    'core_two_factor_recovery_codes' => null,
                    'status' => 'needs_review',
                    'reasons' => ['existing_mapping_requires_review'],
                ];

                continue;
            }

            $reasons = [];
            $normalizedEmail = $this->normalizeEmail($account->email ?? null);

            if ($normalizedEmail === null) {
                $reasons[] = 'missing_email';
            } elseif (($sourceEmailCounts[$normalizedEmail] ?? 0) > 1) {
                $reasons[] = 'duplicate_source_email';
            }

            if ($normalizedEmail !== null && isset($coreEmails[$normalizedEmail])) {
                $reasons[] = 'email_matches_existing_core_account';
            }

            $legacyTwoFactorSecret = in_array('two_factor_secret', $columns, true)
                ? ($account->two_factor_secret ?? null)
                : null;
            $coreTwoFactorSecret = $this->legacySecrets->forCore(
                'analytics',
                $legacyTwoFactorSecret === null ? null : (string) $legacyTwoFactorSecret,
            );
            $legacyRecoveryCodes = in_array('two_factor_recovery_codes', $columns, true)
                ? ($account->two_factor_recovery_codes ?? null)
                : null;
            $coreRecoveryCodes = $this->legacySecrets->forCore(
                'analytics',
                $legacyRecoveryCodes === null ? null : (string) $legacyRecoveryCodes,
            );

            if ($legacyTwoFactorSecret !== null && $legacyTwoFactorSecret !== '' && $coreTwoFactorSecret === null) {
                $reasons[] = 'two_factor_secret_requires_source_app_key';
            }

            if ($legacyRecoveryCodes !== null && $legacyRecoveryCodes !== '' && $coreRecoveryCodes === null) {
                $reasons[] = 'two_factor_recovery_codes_require_source_app_key';
            }

            $passkeyPayloads = [];
            foreach ($sourcePasskeys->get($sourceId, collect()) as $passkey) {
                $credentialId = trim((string) ($passkey->credential_id ?? ''));

                if ($credentialId === '' || isset($coreCredentialIds[$credentialId])) {
                    $reasons[] = $credentialId === '' ? 'passkey_missing_credential_id' : 'passkey_credential_already_linked';

                    continue;
                }

                try {
                    $credential = json_decode((string) $passkey->credential, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $reasons[] = 'passkey_credential_invalid';

                    continue;
                }

                if (! is_array($credential)) {
                    $reasons[] = 'passkey_credential_invalid';

                    continue;
                }

                $passkeyPayloads[] = [
                    'source_id' => (string) $passkey->id,
                    'name' => (string) $passkey->name,
                    'credential_id' => $credentialId,
                    'credential' => json_encode($credential, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'last_used_at' => $passkey->last_used_at ?? null,
                    'created_at' => $passkey->created_at ?? now(),
                    'updated_at' => $passkey->updated_at ?? now(),
                ];
            }

            $plan[] = [
                'source_id' => $sourceId,
                'account' => $account,
                'passkeys' => $passkeyPayloads,
                'core_two_factor_secret' => $coreTwoFactorSecret,
                'core_two_factor_recovery_codes' => $coreRecoveryCodes,
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
            'passkeys_seen' => $sourcePasskeys->sum(fn (Collection $passkeys): int => $passkeys->count()),
            'passkeys_imported' => 0,
        ];

        if (! $apply) {
            return $report;
        }

        foreach ($plan as $item) {
            if ($item['status'] === 'ready'
                && $this->importAccount(
                    $item['account'],
                    $item['core_two_factor_secret'],
                    $item['core_two_factor_recovery_codes'],
                    $item['passkeys'],
                )) {
                $report['imported']++;
                $report['passkeys_imported'] += count($item['passkeys']);
            } elseif ($item['status'] === 'needs_review'
                && $this->recordReview($item['source_id'], $item['reasons'])) {
                $report['review_records_created']++;
            }
        }

        return $report;
    }

    /** @param list<array<string, mixed>> $passkeys */
    private function importAccount(
        object $source,
        ?string $coreTwoFactorSecret,
        ?string $coreRecoveryCodes,
        array $passkeys,
    ): bool {
        $sourceId = (string) $source->id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $coreTwoFactorSecret, $coreRecoveryCodes, $passkeys): bool {
            $existingMap = LegacyIdentityMap::query()
                ->where('source_product', 'analytics')
                ->where('source_entity', 'user')
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                return false;
            }

            $normalizedEmail = $this->normalizeEmail($source->email ?? null);

            if ($normalizedEmail === null || DB::connection('core')->table('users')
                ->where('email_normalized', $normalizedEmail)->exists()) {
                return $this->recordReviewInsideTransaction($sourceId, [
                    $normalizedEmail === null ? 'missing_email' : 'email_matches_existing_core_account',
                ]);
            }

            foreach ($passkeys as $passkey) {
                if (DB::connection('core')->table('passkeys')->where('credential_id', $passkey['credential_id'])->exists()) {
                    return $this->recordReviewInsideTransaction($sourceId, ['passkey_credential_already_linked']);
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
                'password_set_at' => null,
                'auth_type' => null,
                'two_factor_secret' => $coreTwoFactorSecret,
                'two_factor_recovery_codes' => $coreRecoveryCodes,
                'two_factor_confirmed_at' => $source->two_factor_confirmed_at ?? null,
                'preferences' => null,
                'status' => 'active',
                'created_at' => $source->created_at ?? $now,
                'updated_at' => $source->updated_at ?? $now,
            ];

            DB::connection('core')->table('users')->insert($attributes);

            foreach ($passkeys as $passkey) {
                $passkeyId = (string) Str::ulid();
                DB::connection('core')->table('passkeys')->insert([
                    'id' => $passkeyId,
                    'user_id' => $canonicalId,
                    'name' => $passkey['name'],
                    'credential_id' => $passkey['credential_id'],
                    'credential' => $passkey['credential'],
                    'last_used_at' => $passkey['last_used_at'],
                    'created_at' => $passkey['created_at'],
                    'updated_at' => $passkey['updated_at'],
                ]);

                LegacyIdentityMap::query()->create([
                    'source_product' => 'analytics',
                    'source_entity' => 'passkey',
                    'source_id' => (string) $passkey['source_id'],
                    'canonical_entity' => 'passkey',
                    'canonical_id' => $passkeyId,
                    'status' => 'reconciled',
                    'batch_key' => self::BATCH_KEY,
                    'metadata' => ['source_user_id' => $sourceId],
                    'imported_at' => $now,
                    'reconciled_at' => $now,
                ]);
            }

            $mapAttributes = [
                'canonical_entity' => 'user',
                'canonical_id' => $canonicalId,
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => 'Imported as a distinct Core account after eligibility recheck; no email-based account merging was performed.',
                'metadata' => $this->reconciledMetadata($existingMap),
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($existingMap !== null) {
                $existingMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'analytics',
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

        $metadata['imported_by'] = 'platform:import-analytics-identities';

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
            ->where('source_product', 'analytics')
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

        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => null,
            'status' => 'needs_review',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => implode('; ', $reasons),
            'metadata' => ['reason_codes' => $reasons],
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
