<?php

namespace App\Core\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class ImportMonitorAccountsIntoCore
{
    private const BATCH_KEY = 'monitor-account-import-v1';

    /**
     * Inspect Monitor accounts without changing either database.
     *
     * @return array{accounts_seen:int,ready:int,already_mapped:int,needs_review:int,imported:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        if (! Schema::connection('monitor')->hasTable('users')) {
            throw new RuntimeException('The Monitor users table is unavailable on the monitor connection.');
        }

        $sourceAccounts = DB::connection('monitor')->table('users')->orderBy('id')->get();
        $sourceEmailCounts = $sourceAccounts
            ->map(fn (object $account): ?string => $this->normalizeEmail($account->email ?? null))
            ->filter()
            ->countBy();
        $identityMaps = LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', 'user')
            ->get()
            ->keyBy(fn (LegacyIdentityMap $map): string => (string) $map->source_id);
        $coreEmails = DB::connection('core')->table('users')
            ->whereNotNull('email_normalized')
            ->pluck('email_normalized')
            ->mapWithKeys(fn ($email): array => [(string) $email => true]);

        $plan = [];

        foreach ($sourceAccounts as $account) {
            $sourceId = (string) $account->id;
            $mapping = $identityMaps->get($sourceId);

            if ($mapping?->status === 'reconciled') {
                $plan[] = [
                    'source_id' => $sourceId,
                    'account' => $account,
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

            if ($normalizedEmail === null) {
                $reasons[] = 'missing_email';
            } elseif (($sourceEmailCounts[$normalizedEmail] ?? 0) > 1) {
                $reasons[] = 'duplicate_source_email';
            }

            if ($normalizedEmail !== null && isset($coreEmails[$normalizedEmail])) {
                $reasons[] = 'email_matches_existing_core_account';
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
            if ($item['status'] === 'ready' && $this->importAccount($item['account'])) {
                $report['imported']++;
            } elseif ($item['status'] === 'needs_review'
                && $this->recordReview($item['source_id'], $item['reasons'])) {
                $report['review_records_created']++;
            }
        }

        return $report;
    }

    private function importAccount(object $source): bool
    {
        $sourceId = (string) $source->id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId): bool {
            $existingMap = $this->sourceMapping($sourceId, lock: true);

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

            $now = now();
            $canonicalId = (string) Str::ulid();

            DB::connection('core')->table('users')->insert([
                'id' => $canonicalId,
                'name' => $source->name ?? null,
                'email' => $source->email ?? null,
                'email_normalized' => $normalizedEmail,
                'email_verified_at' => $source->email_verified_at ?? null,
                'password' => $source->password ?? null,
                'password_set_at' => null,
                'auth_type' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'preferences' => null,
                'status' => 'active',
                'created_at' => $source->created_at ?? $now,
                'updated_at' => $source->updated_at ?? $now,
            ]);

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
                    'source_product' => 'monitor',
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

        $metadata['imported_by'] = 'platform:import-monitor-identities';

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
        $existingMap = $this->sourceMapping($sourceId, lock: true);

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
            'source_product' => 'monitor',
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

    private function sourceMapping(string $sourceId, bool $lock = false): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', 'user')
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
