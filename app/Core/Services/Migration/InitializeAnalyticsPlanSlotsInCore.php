<?php

namespace App\Core\Services\Migration;

use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Analytics has no source billing system. This initializes a no-charge Core
 * entitlement for each already-reconciled workspace without inventing a price.
 */
final class InitializeAnalyticsPlanSlotsInCore
{
    private const PRODUCT = 'analytics';

    private const BATCH_KEY = 'analytics-legacy-plan-baseline-v1';

    /**
     * @return array{workspaces_seen:int,plan_slots_ready:int,plan_slots_initialized:int,plan_slots_already_initialized:int,workspaces_blocked:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        if (! Schema::connection('analytics')->hasTable('workspaces')) {
            throw new RuntimeException('The Analytics workspaces table is unavailable on the analytics connection.');
        }

        foreach ([
            'legacy_identity_maps', 'workspaces', 'product_subscriptions', 'current_product_subscriptions',
        ] as $table) {
            if (! Schema::connection('core')->hasTable($table)) {
                throw new RuntimeException('Run the Core platform and billing migrations before initializing Analytics plan slots.');
            }
        }

        $workspaces = DB::connection('analytics')->table('workspaces')->orderBy('id')->get();
        $workspaceMaps = $this->mappingsFor('workspace');
        $currentMaps = $this->mappingsFor('current_subscription');
        $report = [
            'workspaces_seen' => $workspaces->count(),
            'plan_slots_ready' => 0,
            'plan_slots_initialized' => 0,
            'plan_slots_already_initialized' => 0,
            'workspaces_blocked' => 0,
            'review_records_created' => 0,
        ];

        foreach ($workspaces as $source) {
            $sourceId = (string) $source->id;
            $currentMap = $currentMaps->get($sourceId);
            $workspaceMap = $workspaceMaps->get($sourceId);
            $workspaceId = $workspaceMap?->status === 'reconciled'
                && $workspaceMap->canonical_entity === 'workspace'
                ? (string) $workspaceMap->canonical_id
                : null;

            if ($currentMap?->status === 'reconciled') {
                if ($this->isInitializedMapping($currentMap, $workspaceId)) {
                    $report['plan_slots_already_initialized']++;
                } else {
                    $report['workspaces_blocked']++;
                }

                continue;
            }

            $reasons = [];
            if ($workspaceId === null || ! Workspace::query()->whereKey($workspaceId)->exists()) {
                $reasons[] = 'analytics_workspace_not_reconciled';
            }

            if ($currentMap !== null && ($currentMap->status !== 'needs_review' || $currentMap->canonical_id !== null)) {
                $reasons[] = 'existing_analytics_subscription_mapping_requires_review';
            }

            if ($workspaceId !== null && CurrentProductSubscription::query()
                ->where('workspace_id', $workspaceId)
                ->where('product', self::PRODUCT)
                ->exists()) {
                $reasons[] = 'core_analytics_subscription_slot_already_occupied';
            }

            if ($reasons !== []) {
                $report['workspaces_blocked']++;
                if ($apply && $this->recordReview($sourceId, $reasons, $currentMap)) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['plan_slots_ready']++;

            if (! $apply) {
                continue;
            }

            $result = $this->initialize($source, $workspaceId, $currentMap);

            if ($result['initialized']) {
                $report['plan_slots_initialized']++;
            } else {
                $report['workspaces_blocked']++;
                if ($this->recordReview($sourceId, $result['reason_codes'], $currentMap)) {
                    $report['review_records_created']++;
                }
            }
        }

        return $report;
    }

    /** @param array<string, string> $reasonCodes */
    private function initialize(object $source, string $workspaceId, ?LegacyIdentityMap $existingMap): array
    {
        $sourceId = (string) $source->id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $workspaceId, $existingMap): array {
            $currentMap = $this->sourceMapping('current_subscription', $sourceId, lock: true) ?? $existingMap;

            if ($currentMap !== null && ($currentMap->status !== 'needs_review' || $currentMap->canonical_id !== null)) {
                return ['initialized' => false, 'reason_codes' => ['existing_analytics_subscription_mapping_requires_review']];
            }

            $workspace = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
            if ($workspace === null) {
                return ['initialized' => false, 'reason_codes' => ['canonical_analytics_workspace_missing']];
            }

            if (CurrentProductSubscription::query()
                ->where('workspace_id', $workspaceId)
                ->where('product', self::PRODUCT)
                ->lockForUpdate()
                ->exists()) {
                return ['initialized' => false, 'reason_codes' => ['core_analytics_subscription_slot_already_occupied']];
            }

            $now = now();
            $subscription = ProductSubscription::query()->create([
                'workspace_id' => $workspaceId,
                'billing_customer_id' => null,
                'product' => self::PRODUCT,
                'provider' => 'legacy_access',
                'provider_account_key' => self::PRODUCT,
                'provider_subscription_id' => null,
                'provider_price_id' => null,
                'plan_key' => 'legacy_access',
                'status' => 'active',
                'quantity' => 1,
                'current_period_starts_at' => null,
                'current_period_ends_at' => null,
                'cancel_at' => null,
                'canceled_at' => null,
                'metadata' => [
                    'migration_source' => self::PRODUCT,
                    'entitlement_snapshot' => true,
                    'billing_state' => [
                        'kind' => 'no_charge_legacy_access_baseline',
                        'source_workspace_id' => $sourceId,
                        'source_has_billing_catalog' => false,
                    ],
                    'plan_snapshot' => $this->legacyAccessSnapshot(),
                ],
            ]);
            $this->preserveTimestamps($subscription, $source);

            $assignment = CurrentProductSubscription::query()->create([
                'workspace_id' => $workspaceId,
                'product' => self::PRODUCT,
                'product_subscription_id' => $subscription->getKey(),
            ]);
            $mapAttributes = [
                'canonical_entity' => 'current_product_subscription',
                'canonical_id' => (string) $assignment->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => 'Initialized no-charge legacy Analytics access; the audited source has no billing catalog or subscription records.',
                'metadata' => array_merge($this->reconciledMetadata($currentMap), [
                    'product_subscription_id' => (string) $subscription->getKey(),
                    'plan_key' => 'legacy_access',
                    'provider' => 'legacy_access',
                ]),
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($currentMap !== null) {
                $currentMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => self::PRODUCT,
                    'source_entity' => 'current_subscription',
                    'source_id' => $sourceId,
                    ...$mapAttributes,
                ]);
            }

            return ['initialized' => true, 'reason_codes' => []];
        });
    }

    /** @return array<string, mixed> */
    private function legacyAccessSnapshot(): array
    {
        return [
            'name' => 'Existing Analytics access',
            'description' => 'No-charge migration baseline while Analytics billing tiers are designed.',
            'entitlements' => ['*'],
            'limits' => [
                'sites' => null,
                'members' => null,
                'events_per_month' => null,
                'retention_days' => max(1, (int) config('analytics.event_retention_days', 90)),
                'aggregate_retention_months' => max(1, (int) config('analytics.aggregate_retention_months', 13)),
                'export_retention_hours' => max(1, (int) config('analytics.export_retention_hours', 24)),
            ],
        ];
    }

    private function isInitializedMapping(LegacyIdentityMap $mapping, ?string $workspaceId): bool
    {
        if ($workspaceId === null || $mapping->canonical_entity !== 'current_product_subscription') {
            return false;
        }

        $assignment = CurrentProductSubscription::query()->find($mapping->canonical_id);
        $subscription = $assignment?->subscription()->first();

        return $assignment !== null
            && (string) $assignment->workspace_id === $workspaceId
            && $assignment->product === self::PRODUCT
            && $subscription instanceof ProductSubscription
            && (string) $subscription->workspace_id === $workspaceId
            && $subscription->product === self::PRODUCT
            && $subscription->provider === 'legacy_access'
            && $subscription->plan_key === 'legacy_access'
            && $subscription->status === 'active';
    }

    /** @param list<string> $reasonCodes */
    private function recordReview(string $sourceId, array $reasonCodes, ?LegacyIdentityMap $existing = null): bool
    {
        $mapping = $this->sourceMapping('current_subscription', $sourceId) ?? $existing;

        if ($mapping !== null && ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null)) {
            return false;
        }

        $now = now();
        $metadata = $mapping?->metadata ?? [];
        $history = is_array($metadata['review_history'] ?? null) ? $metadata['review_history'] : [];
        $history[] = ['reason_codes' => array_values(array_unique($reasonCodes)), 'recorded_at' => $now->toISOString()];
        $attributes = [
            'canonical_entity' => null,
            'canonical_id' => null,
            'status' => 'needs_review',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => implode(', ', $reasonCodes),
            'metadata' => array_merge($metadata, [
                'source_product' => self::PRODUCT,
                'source_entity' => 'current_subscription',
                'source_id' => $sourceId,
                'reason_codes' => array_values(array_unique($reasonCodes)),
                'review_history' => $history,
            ]),
            'imported_at' => $mapping?->imported_at ?? $now,
        ];

        if ($mapping !== null) {
            $mapping->fill($attributes)->save();
        } else {
            LegacyIdentityMap::query()->create([
                'source_product' => self::PRODUCT,
                'source_entity' => 'current_subscription',
                'source_id' => $sourceId,
                ...$attributes,
            ]);
        }

        return true;
    }

    /** @return Collection<string, LegacyIdentityMap> */
    private function mappingsFor(string $entity): Collection
    {
        return LegacyIdentityMap::query()
            ->where('source_product', self::PRODUCT)
            ->where('source_entity', $entity)
            ->get()
            ->keyBy('source_id');
    }

    private function sourceMapping(string $entity, string $sourceId, bool $lock = false): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', self::PRODUCT)
            ->where('source_entity', $entity)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return array<string, mixed> */
    private function reconciledMetadata(?LegacyIdentityMap $mapping): array
    {
        $metadata = $mapping?->metadata ?? [];
        $metadata['migration_source'] = self::PRODUCT;

        return $metadata;
    }

    private function preserveTimestamps(Model $target, object $source): void
    {
        $createdAt = $source->created_at ?? null;
        $updatedAt = $source->updated_at ?? $createdAt;
        $dates = array_filter(['created_at' => $createdAt, 'updated_at' => $updatedAt]);

        if ($dates !== []) {
            $target->forceFill($dates)->saveQuietly();
        }
    }
}
