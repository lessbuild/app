<?php

namespace App\Modules\Deployer\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceFeedback;
use App\Modules\Deployer\Models\ProductFeedback;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Import Deployer feedback into Core only when every source identity resolves
 * through an explicit, reconciled map. Source ciphertext is read through the
 * Deployer model cast and written through Core's independent encrypted casts.
 */
final class ImportFeedbackIntoCore
{
    private const BATCH_KEY = 'deployer-feedback-import-v1';

    private const SOURCE_ENTITY = 'product_feedback';

    private const TARGET_ENTITY = 'workspace_feedback';

    /**
     * Preview or copy the latest source revision of each safely mapped record.
     *
     * @return array{feedback_seen:int,feedback_ready:int,feedback_imported:int,feedback_updated:int,feedback_already_current:int,feedback_blocked:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        $this->assertSchemaReady();

        $report = [
            'feedback_seen' => 0,
            'feedback_ready' => 0,
            'feedback_imported' => 0,
            'feedback_updated' => 0,
            'feedback_already_current' => 0,
            'feedback_blocked' => 0,
            'review_records_created' => 0,
        ];

        ProductFeedback::query()
            ->orderBy('id')
            ->chunkById(100, function (EloquentCollection $feedback) use ($apply, &$report): void {
                foreach ($feedback as $source) {
                    $report['feedback_seen']++;
                    $this->process($source, $apply, $report);
                }
            });

        return $report;
    }

    private function process(ProductFeedback $source, bool $apply, array &$report): void
    {
        try {
            $sourceData = $this->sourceData($source);
            $decryptionError = false;
        } catch (Throwable) {
            $sourceData = null;
            $decryptionError = true;
        }

        $operation = function () use ($source, $sourceData, $decryptionError, $apply, &$report): void {
            $mapping = $this->sourceMapping(self::SOURCE_ENTITY, (string) $source->getKey(), $apply);
            $workspaceMap = $this->sourceMapping('organization', (string) $source->organization_id, $apply);
            $submitterMap = $this->sourceMapping('user', (string) $source->user_id, $apply);
            $reviewerMap = $source->reviewed_by === null
                ? null
                : $this->sourceMapping('user', (string) $source->reviewed_by, $apply);

            $reasons = [];
            if ($decryptionError) {
                $reasons[] = 'deployer_feedback_ciphertext_unreadable';
            }

            if ($sourceData !== null) {
                $reasons = [...$reasons, ...$this->validateSourceData($sourceData)];
            }

            $workspaceId = $this->canonicalId($workspaceMap, 'workspace');
            $submitterId = $this->canonicalId($submitterMap, 'user');
            $reviewerId = $source->reviewed_by === null
                ? null
                : $this->canonicalId($reviewerMap, 'user');

            if ($workspaceId === null || ! Workspace::query()->whereKey($workspaceId)->exists()) {
                $reasons[] = 'deployer_feedback_workspace_not_reconciled';
            }

            if ($submitterId === null || ! PlatformUser::query()->whereKey($submitterId)->exists()) {
                $reasons[] = 'deployer_feedback_submitter_not_reconciled';
            }

            if ($source->reviewed_by !== null
                && ($reviewerId === null || ! PlatformUser::query()->whereKey($reviewerId)->exists())) {
                $reasons[] = 'deployer_feedback_reviewer_not_reconciled';
            }

            $target = null;
            if ($mapping !== null) {
                if ($mapping->batch_key !== self::BATCH_KEY) {
                    $reasons[] = 'deployer_feedback_mapping_owned_by_another_reconciliation';
                } elseif ($mapping->status === 'reconciled'
                    && $mapping->canonical_entity === self::TARGET_ENTITY
                    && filled($mapping->canonical_id)) {
                    $target = WorkspaceFeedback::query()->find($mapping->canonical_id);
                    if ($target === null || $target->product !== 'deployer') {
                        $reasons[] = 'deployer_feedback_canonical_record_missing_or_invalid';
                    }
                } elseif ($mapping->status !== 'needs_review'
                    || $mapping->canonical_id !== null
                    || $mapping->canonical_entity !== null) {
                    $reasons[] = 'deployer_feedback_mapping_requires_manual_review';
                }
            }

            $reasons = array_values(array_unique($reasons));
            if ($reasons !== []) {
                $report['feedback_blocked']++;
                if ($apply && $this->recordReview($source, $mapping, $reasons)) {
                    $report['review_records_created']++;
                }

                return;
            }

            $report['feedback_ready']++;
            $sourceHash = $this->fingerprint(
                $sourceData,
                (string) $workspaceId,
                (string) $submitterId,
                $reviewerId,
            );

            if ($target !== null && ($mapping->metadata['source_hash'] ?? null) === $sourceHash) {
                $report['feedback_already_current']++;

                return;
            }

            if (! $apply) {
                return;
            }

            $attributes = $this->targetAttributes(
                $sourceData,
                (string) $workspaceId,
                (string) $submitterId,
                $reviewerId,
            );

            if ($target === null) {
                $target = WorkspaceFeedback::query()->create($attributes);
                $report['feedback_imported']++;
            } else {
                $target->forceFill($attributes)->save();
                $report['feedback_updated']++;
            }

            $mapping ??= new LegacyIdentityMap([
                'source_product' => 'deployer',
                'source_entity' => self::SOURCE_ENTITY,
                'source_id' => (string) $source->getKey(),
                'batch_key' => self::BATCH_KEY,
                'imported_at' => now(),
            ]);
            $mapping->forceFill([
                'canonical_entity' => self::TARGET_ENTITY,
                'canonical_id' => (string) $target->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => null,
                'metadata' => [
                    'source_hash' => $sourceHash,
                    'source_updated_at' => $sourceData['updated_at'],
                    'workspace_id' => (string) $workspaceId,
                    'field_set_version' => 1,
                ],
                'imported_at' => $mapping->imported_at ?? now(),
                'reconciled_at' => now(),
            ])->save();
        };

        if ($apply) {
            DB::connection('core')->transaction($operation);
        } else {
            $operation();
        }
    }

    /**
     * @return array{category:string,severity:string,status:string,title:string,description:string,reproduction_steps:?string,review_response:?string,page:?string,resolved_at:?string,created_at:string,updated_at:string}
     */
    private function sourceData(ProductFeedback $source): array
    {
        return [
            'category' => (string) $source->category,
            'severity' => (string) $source->severity,
            'status' => (string) $source->status,
            'title' => (string) $source->title,
            'description' => (string) $source->description,
            'reproduction_steps' => $source->reproduction_steps === null ? null : (string) $source->reproduction_steps,
            'review_response' => $source->review_response === null ? null : (string) $source->review_response,
            'page' => $source->page === null ? null : (string) $source->page,
            'resolved_at' => $source->getRawOriginal('resolved_at'),
            'created_at' => (string) $source->getRawOriginal('created_at'),
            'updated_at' => (string) $source->getRawOriginal('updated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceData
     * @return list<string>
     */
    private function validateSourceData(array $sourceData): array
    {
        $reasons = [];
        if (! in_array($sourceData['category'], WorkspaceFeedback::CATEGORIES, true)) {
            $reasons[] = 'deployer_feedback_category_unsupported';
        }

        if (! in_array($sourceData['severity'], WorkspaceFeedback::SEVERITIES, true)) {
            $reasons[] = 'deployer_feedback_severity_unsupported';
        }

        if (! in_array($sourceData['status'], WorkspaceFeedback::STATUSES, true)) {
            $reasons[] = 'deployer_feedback_status_unsupported';
        }

        if (trim($sourceData['title']) === '' || Str::length($sourceData['title']) > 160) {
            $reasons[] = 'deployer_feedback_title_invalid';
        }

        if ($sourceData['page'] !== null && Str::length($sourceData['page']) > 500) {
            $reasons[] = 'deployer_feedback_page_invalid';
        }

        if ($sourceData['created_at'] === '' || $sourceData['updated_at'] === '') {
            $reasons[] = 'deployer_feedback_timestamps_missing';
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $sourceData
     * @return array<string, mixed>
     */
    private function targetAttributes(
        array $sourceData,
        string $workspaceId,
        string $submitterId,
        ?string $reviewerId,
    ): array {
        return [
            'workspace_id' => $workspaceId,
            'user_id' => $submitterId,
            'reviewed_by_user_id' => $reviewerId,
            'product' => 'deployer',
            'category' => $sourceData['category'],
            'severity' => $sourceData['severity'],
            'status' => $sourceData['status'],
            'title' => $sourceData['title'],
            'description' => $sourceData['description'],
            'reproduction_steps' => $sourceData['reproduction_steps'],
            'review_response' => $sourceData['review_response'],
            'page' => $sourceData['page'],
            'resolved_at' => $sourceData['resolved_at'],
            'created_at' => $sourceData['created_at'],
            'updated_at' => $sourceData['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceData
     */
    private function fingerprint(
        array $sourceData,
        string $workspaceId,
        string $submitterId,
        ?string $reviewerId,
    ): string {
        return hash_hmac('sha256', json_encode([
            'workspace_id' => $workspaceId,
            'submitter_id' => $submitterId,
            'reviewer_id' => $reviewerId,
            ...$sourceData,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    private function sourceMapping(string $entity, string $sourceId, bool $lock): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', $entity)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function canonicalId(?LegacyIdentityMap $mapping, string $entity): ?string
    {
        return $mapping?->status === 'reconciled'
            && $mapping->canonical_entity === $entity
            && filled($mapping->canonical_id)
                ? (string) $mapping->canonical_id
                : null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function recordReview(
        ProductFeedback $source,
        ?LegacyIdentityMap $mapping,
        array $reasons,
    ): bool {
        if ($mapping !== null && (
            $mapping->batch_key !== self::BATCH_KEY
            || $mapping->status !== 'needs_review'
            || $mapping->canonical_id !== null
            || $mapping->canonical_entity !== null
        )) {
            return false;
        }

        $mapping ??= new LegacyIdentityMap([
            'source_product' => 'deployer',
            'source_entity' => self::SOURCE_ENTITY,
            'source_id' => (string) $source->getKey(),
            'batch_key' => self::BATCH_KEY,
            'imported_at' => now(),
        ]);

        $isNew = ! $mapping->exists;
        $mapping->forceFill([
            'status' => 'needs_review',
            'canonical_entity' => null,
            'canonical_id' => null,
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => implode(', ', $reasons),
            'metadata' => [
                'reason_codes' => $reasons,
                'source_updated_at' => $source->getRawOriginal('updated_at'),
            ],
            'imported_at' => $mapping->imported_at ?? now(),
        ])->save();

        return $isNew;
    }

    private function assertSchemaReady(): void
    {
        if (! Schema::connection('deployer')->hasTable('product_feedback')) {
            throw new RuntimeException('The Deployer product_feedback table is unavailable on the deployer connection.');
        }

        foreach (['legacy_identity_maps', 'users', 'workspaces', 'workspace_feedback'] as $table) {
            if (! Schema::connection('core')->hasTable($table)) {
                throw new RuntimeException('Run the Core identity, workspace, and feedback migrations before importing Deployer feedback.');
            }
        }
    }
}
