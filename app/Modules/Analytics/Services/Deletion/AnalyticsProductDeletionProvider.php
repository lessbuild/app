<?php

namespace App\Modules\Analytics\Services\Deletion;

use App\Core\Contracts\ProductDeletionProvider;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionPreview;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Services\Deletion\DeletionAuthority;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AnalyticsProductDeletionProvider implements ProductDeletionProvider
{
    private const RETAINED = ['A minimal Analytics deletion receipt and tombstone prevents the deleted native target from being recreated.'];

    public function product(): string
    {
        return 'analytics';
    }

    public function inspect(ProductDeletionTarget $target): ProductDeletionPreview
    {
        if (! in_array($target->kind, ['account', 'workspace'], true)) {
            return new ProductDeletionPreview(['unsupported_target']);
        }

        if ($target->kind === 'workspace') {
            $workspace = Workspace::query()->find($target->sourceId);

            if ($workspace === null) {
                return $this->hasCompletedReceiptForTarget($target)
                    ? new ProductDeletionPreview([], self::RETAINED)
                    : new ProductDeletionPreview(['native_target_missing']);
            }

            $blockers = $this->workspaceBlockers($workspace, $target->actorSourceId);

            return new ProductDeletionPreview($blockers, self::RETAINED, $this->workspaceCounts([$workspace->getKey()]));
        }

        $user = User::query()->find($target->sourceId);

        if ($user === null) {
            return $this->hasCompletedReceiptForTarget($target)
                ? new ProductDeletionPreview([], self::RETAINED)
                : new ProductDeletionPreview(['native_target_missing']);
        }

        if ((string) $target->actorSourceId !== (string) $user->getKey()) {
            return new ProductDeletionPreview(['owner_required']);
        }

        $owned = $user->workspaces()->wherePivot('role', 'owner')->pluck('workspaces.id')->map(fn ($id) => (string) $id)->all();
        $included = array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
        sort($owned);
        sort($included);
        $blockers = $owned === $included ? [] : ['workspace_scope_incomplete'];

        $foreignMembership = DB::connection('analytics')->table('workspace_user')
            ->where('user_id', $user->getKey())
            ->whereNotIn('workspace_id', $included === [] ? ['0'] : $included)
            ->exists();

        if ($foreignMembership) {
            $blockers[] = 'foreign_workspace_membership';
        }

        if ($this->hasForeignReportExports((string) $user->getKey(), $included)) {
            $blockers[] = 'foreign_report_exports';
        }

        foreach ($included as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);

            if ($workspace === null) {
                $blockers[] = 'workspace_owner_mismatch';

                continue;
            }

            $blockers = array_merge($blockers, $this->workspaceBlockers($workspace, (string) $user->getKey()));
        }

        return new ProductDeletionPreview(array_values(array_unique($blockers)), self::RETAINED, $this->workspaceCounts($included));
    }

    public function prepare(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        if ($attempt->phase !== 'prepare' || $attempt->target->product !== $this->product()) {
            return new ProductDeletionResult('blocked', 'phase_invalid');
        }
        if (! in_array($attempt->target->kind, ['account', 'workspace'], true)) {
            return new ProductDeletionResult('blocked', 'unsupported_target');
        }

        $this->assertFreshAuthority($attempt);
        $target = $attempt->target;
        $connection = DB::connection('analytics');
        $status = $connection->transaction(function () use ($attempt, $target, $connection): string {
            $this->assertFreshAuthority($attempt);
            $source = $this->lockNativeTarget($target);
            if ($source === null) {
                return 'blocked:native_target_missing';
            }
            $this->assertFreshAuthority($attempt);
            if ($target->kind === 'workspace') {
                $this->assertNativeScope($target, $source);
            } elseif (! $source instanceof User || (string) $source->getKey() !== (string) $target->actorSourceId) {
                throw new DeletionBlocked('owner_required');
            }

            $tombstone = $connection->table('analytics_deletion_tombstones')
                ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)
                ->lockForUpdate()->first();
            if ($tombstone !== null) {
                $this->assertBinding($tombstone, $attempt);
            } else {
                $tombstoneId = $connection->table('analytics_deletion_tombstones')->insertGetId([
                    'kind' => $target->kind,
                    'source_id' => (string) $target->sourceId,
                    'canonical_id' => $target->canonicalId,
                    'request_id' => $attempt->requestId,
                    'prepare_step_id' => $attempt->stepId,
                    'payload_hash' => $attempt->payloadHash,
                    'status' => 'fencing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $tombstone = $connection->table('analytics_deletion_tombstones')->where('id', $tombstoneId)->first();
            }

            $this->settleQueuedActivity($target);
            $hasLiveActivity = $this->liveActivityExists($target);
            $this->assertFreshAuthority($attempt);
            $this->assertNativeScope($target, $source);
            $connection->table('analytics_deletion_tombstones')->where('id', $tombstone->id)->update([
                'status' => $hasLiveActivity ? 'fencing' : 'prepared',
                'updated_at' => now(),
            ]);
            $this->upsertReceipt($attempt, 'prepare', $hasLiveActivity ? 'fencing' : 'completed');

            return $hasLiveActivity ? 'waiting:activity_draining' : 'ready';
        }, attempts: 3);

        if (str_starts_with($status, 'blocked:')) {
            return new ProductDeletionResult('blocked', substr($status, strlen('blocked:')));
        }

        return $status === 'ready'
            ? new ProductDeletionResult('ready')
            : new ProductDeletionResult('waiting', substr($status, strlen('waiting:')));
    }

    public function purge(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        if ($attempt->phase !== 'purge' || $attempt->target->product !== $this->product()) {
            return new ProductDeletionResult('blocked', 'phase_invalid');
        }
        if (! in_array($attempt->target->kind, ['account', 'workspace'], true)) {
            return new ProductDeletionResult('blocked', 'unsupported_target');
        }

        $this->assertFreshAuthority($attempt);
        $target = $attempt->target;
        $connection = DB::connection('analytics');
        $prepare = $connection->transaction(function () use ($attempt, $target, $connection): string {
            $this->assertFreshAuthority($attempt);
            $tombstone = $connection->table('analytics_deletion_tombstones')
                ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)
                ->lockForUpdate()->first();
            if ($tombstone === null) {
                return $this->matchingCompletedPurgeReceipt($attempt) ? 'completed' : 'blocked:missing_preparation_receipt';
            }
            $this->assertBinding($tombstone, $attempt);
            if ($tombstone->status === 'completed') {
                return $this->matchingCompletedPurgeReceipt($attempt) ? 'completed' : 'blocked:missing_purge_receipt';
            }

            $source = $this->lockNativeTarget($target);
            if ($source === null) {
                return 'blocked:native_target_missing';
            }
            $this->assertFreshAuthority($attempt);
            if ($target->kind === 'workspace') {
                $this->assertNativeScope($target, $source);
            } else {
                $this->assertAccountWorkspaceScopePurged($target, $source);
            }
            $prepareReceipt = $connection->table('analytics_deletion_receipts')
                ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)->first();
            if ($prepareReceipt === null || $prepareReceipt->phase !== 'prepare'
                || $prepareReceipt->status !== 'completed' || ! $this->receiptMatches($prepareReceipt, $attempt)) {
                return 'blocked:missing_preparation_receipt';
            }

            $this->settleQueuedActivity($target);
            if ($this->liveActivityExists($target)) {
                return 'waiting:activity_draining';
            }

            if ($this->pendingSiteDeletionExists($target)) {
                return 'waiting:site_deletion_pending';
            }

            $this->recordExportFiles($target, (int) $tombstone->id);
            $this->assertFreshAuthority($attempt);
            if ($target->kind === 'workspace') {
                $this->assertNativeScope($target, $source);
            } else {
                $this->assertAccountWorkspaceScopePurged($target, $source);
            }

            return 'purge';
        }, attempts: 3);

        if ($prepare === 'completed') {
            return new ProductDeletionResult('completed', null, self::RETAINED);
        }
        if (str_starts_with($prepare, 'blocked:')) {
            return new ProductDeletionResult('blocked', substr($prepare, strlen('blocked:')));
        }
        if (str_starts_with($prepare, 'waiting:')) {
            return new ProductDeletionResult('waiting', substr($prepare, strlen('waiting:')));
        }

        $tombstone = $connection->table('analytics_deletion_tombstones')
            ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)->first();
        foreach ($connection->table('analytics_deletion_files')->where('tombstone_id', $tombstone->id)->where('status', 'pending')->get() as $file) {
            try {
                Storage::disk($file->disk)->delete($file->path);
                if (Storage::disk($file->disk)->exists($file->path)) {
                    return new ProductDeletionResult('waiting', 'export_file_cleanup_pending');
                }

                $connection->table('analytics_deletion_files')->where('id', $file->id)->update(['status' => 'deleted', 'updated_at' => now()]);
            } catch (\Throwable) {
                return new ProductDeletionResult('waiting', 'export_file_cleanup_pending');
            }
        }

        if ($connection->table('analytics_deletion_files')->where('tombstone_id', $tombstone->id)->where('status', '!=', 'deleted')->exists()) {
            return new ProductDeletionResult('waiting', 'export_file_cleanup_pending');
        }

        $result = $connection->transaction(function () use ($connection, $target, $attempt): string {
            $tombstone = $connection->table('analytics_deletion_tombstones')
                ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)
                ->lockForUpdate()->first();
            if ($tombstone === null) {
                return $this->matchingCompletedPurgeReceipt($attempt) ? 'completed' : 'blocked:missing_preparation_receipt';
            }
            $this->assertBinding($tombstone, $attempt);
            if ($tombstone->status === 'completed') {
                return $this->matchingCompletedPurgeReceipt($attempt) ? 'completed' : 'blocked:missing_purge_receipt';
            }

            $source = $this->lockNativeTarget($target);
            if ($source === null) {
                return 'blocked:native_target_missing';
            }
            $this->assertFreshAuthority($attempt);
            if ($target->kind === 'workspace') {
                $this->assertNativeScope($target, $source);
            } else {
                $this->assertAccountWorkspaceScopePurged($target, $source);
            }
            if ($connection->table('analytics_deletion_files')->where('tombstone_id', $tombstone->id)->where('status', '!=', 'deleted')->exists()) {
                return 'waiting:export_file_cleanup_pending';
            }
            if ($this->pendingSiteDeletionExists($target)) {
                return 'waiting:site_deletion_pending';
            }

            if ($target->kind === 'workspace') {
                $this->purgeWorkspace($source);
            } else {
                $this->assertAccountWorkspaceScopePurged($target, $source);
                $this->purgeAccountIdentityData($source);
                $source->workspaces()->detach();
                $source->delete();
            }

            $this->assertFreshAuthority($attempt);
            $connection->table('analytics_deletion_tombstones')->where('id', $tombstone->id)->update([
                'status' => 'completed', 'updated_at' => now(),
            ]);
            $this->upsertReceipt($attempt, 'purge', 'completed', self::RETAINED);

            return 'completed';
        }, attempts: 3);

        if (str_starts_with($result, 'blocked:')) {
            return new ProductDeletionResult('blocked', substr($result, strlen('blocked:')));
        }
        if (str_starts_with($result, 'waiting:')) {
            return new ProductDeletionResult('waiting', substr($result, strlen('waiting:')));
        }

        return new ProductDeletionResult('completed', null, self::RETAINED);
    }

    private function workspaceCounts(array $workspaceIds): array
    {
        $siteIds = Site::query()->withTrashed()->whereIn('workspace_id', $workspaceIds)->pluck('id');

        return [
            'sites' => $siteIds->count(),
            'events' => DB::connection('analytics')->table('analytics_events')->whereIn('site_id', $siteIds)->count(),
            'visits' => DB::connection('analytics')->table('visits')->whereIn('site_id', $siteIds)->count(),
            'exports' => ReportExport::query()->whereIn('workspace_id', $workspaceIds)->count(),
        ];
    }

    private function workspaceOwnerMatches(Workspace $workspace, string $actorId): bool
    {
        return DB::connection('analytics')->table('workspace_user')
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $actorId)
            ->where('role', 'owner')
            ->exists();
    }

    /** @return list<string> */
    private function workspaceBlockers(Workspace $workspace, string $actorId): array
    {
        $blockers = $this->workspaceOwnerMatches($workspace, $actorId) ? [] : ['owner_required'];
        if (DB::connection('analytics')->table('workspace_user')->where('workspace_id', $workspace->getKey())
            ->where('user_id', '!=', $actorId)->exists()) {
            $blockers[] = 'workspace_has_teammates';
        }

        return $blockers;
    }

    private function lockNativeTarget(ProductDeletionTarget $target): User|Workspace|null
    {
        $connection = DB::connection('analytics');
        if ($target->kind === 'workspace') {
            $connection->table('workspaces')->where('id', $target->sourceId)->update(['id' => DB::raw('id')]);

            return Workspace::query()->whereKey($target->sourceId)->lockForUpdate()->first();
        }

        $connection->table('users')->where('id', $target->sourceId)->update(['id' => DB::raw('id')]);

        return User::query()->whereKey($target->sourceId)->lockForUpdate()->first();
    }

    private function assertNativeScope(ProductDeletionTarget $target, User|Workspace $source): void
    {
        if ($target->kind === 'workspace') {
            $blockers = $this->workspaceBlockers($source, $target->actorSourceId);
            if ($blockers !== []) {
                throw new DeletionBlocked($blockers[0]);
            }

            return;
        }

        if (! $source instanceof User || (string) $source->getKey() !== (string) $target->actorSourceId) {
            throw new DeletionBlocked('owner_required');
        }
        $included = array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
        $owned = DB::connection('analytics')->table('workspace_user')->where('user_id', $source->getKey())
            ->where('role', 'owner')->pluck('workspace_id')->map(fn ($id): string => (string) $id)->all();
        sort($owned);
        $sortedIncluded = $included;
        sort($sortedIncluded);
        if ($owned !== $sortedIncluded) {
            throw new DeletionBlocked('workspace_scope_incomplete');
        }
        if (DB::connection('analytics')->table('workspace_user')->where('user_id', $source->getKey())
            ->whereNotIn('workspace_id', $included === [] ? ['0'] : $included)->exists()) {
            throw new DeletionBlocked('foreign_workspace_membership');
        }
        if ($this->hasForeignReportExports((string) $source->getKey(), $included, lock: true)) {
            throw new DeletionBlocked('foreign_report_exports');
        }

        foreach ($included as $workspaceId) {
            DB::connection('analytics')->table('workspaces')->where('id', $workspaceId)->update(['id' => DB::raw('id')]);
        }
        $workspaces = Workspace::query()->whereIn('id', $included)->orderBy('id')->lockForUpdate()->get();
        if ($workspaces->count() !== count($included)) {
            throw new DeletionBlocked('workspace_scope_incomplete');
        }
        foreach ($workspaces as $workspace) {
            $blockers = $this->workspaceBlockers($workspace, (string) $source->getKey());
            if ($blockers !== []) {
                throw new DeletionBlocked($blockers[0]);
            }
        }
    }

    private function assertAccountWorkspaceScopePurged(ProductDeletionTarget $target, User $user): void
    {
        $workspaceIds = array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
        if (DB::connection('analytics')->table('workspace_user')->where('user_id', $user->getKey())->where('role', 'owner')->exists()
            || DB::connection('analytics')->table('workspace_user')->where('user_id', $user->getKey())->exists()) {
            throw new DeletionBlocked('workspace_cleanup_incomplete');
        }
        if ($this->hasForeignReportExports((string) $user->getKey(), $workspaceIds, lock: true)) {
            throw new DeletionBlocked('foreign_report_exports');
        }

        $requestId = $this->currentRequestId($target);
        foreach ($workspaceIds as $workspaceId) {
            if (Workspace::query()->whereKey($workspaceId)->exists()
                || DB::connection('analytics')->table('workspace_user')->where('workspace_id', $workspaceId)->exists()
                || ! $this->nativeTargetHasCompletedReceipt('workspace', $workspaceId, $requestId)) {
                throw new DeletionBlocked('workspace_cleanup_incomplete');
            }
        }
    }

    private function currentRequestId(ProductDeletionTarget $target): string
    {
        $tombstone = DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', 'account')->where('source_id', $target->sourceId)->first();

        if ($tombstone === null) {
            throw new DeletionBlocked('missing_preparation_receipt');
        }

        return (string) $tombstone->request_id;
    }

    private function nativeTargetHasCompletedReceipt(string $kind, string $sourceId, string $requestId): bool
    {
        $tombstone = DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', $kind)->where('source_id', $sourceId)->where('request_id', $requestId)
            ->where('status', 'completed')->first();
        $receipt = DB::connection('analytics')->table('analytics_deletion_receipts')
            ->where('kind', $kind)->where('source_id', $sourceId)->where('request_id', $requestId)
            ->where('phase', 'purge')->where('status', 'completed')->first();

        return $tombstone !== null && $receipt !== null
            && (string) $receipt->step_id === (string) $tombstone->prepare_step_id
            && hash_equals((string) $receipt->payload_hash, (string) $tombstone->payload_hash);
    }

    /** @param list<string> $includedWorkspaceIds */
    private function hasForeignReportExports(string $userId, array $includedWorkspaceIds, bool $lock = false): bool
    {
        $query = DB::connection('analytics')->table('report_exports')
            ->where('requested_by', $userId)
            ->whereNotIn('workspace_id', $includedWorkspaceIds === [] ? ['0'] : $includedWorkspaceIds);

        return $lock ? $query->lockForUpdate()->first() !== null : $query->exists();
    }

    private function matchingCompletedPurgeReceipt(ProductDeletionAttempt $attempt): bool
    {
        $target = $attempt->target;
        $tombstone = DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)
            ->where('canonical_id', $target->canonicalId)->where('status', 'completed')->first();
        $receipt = DB::connection('analytics')->table('analytics_deletion_receipts')
            ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)
            ->where('request_id', $attempt->requestId)->where('step_id', $attempt->stepId)
            ->where('payload_hash', $attempt->payloadHash)->where('phase', 'purge')->where('status', 'completed')->first();

        return $tombstone !== null && $receipt !== null && $this->receiptMatches($receipt, $attempt)
            && (string) $tombstone->request_id === $attempt->requestId
            && (string) $tombstone->prepare_step_id === $attempt->stepId
            && hash_equals((string) $tombstone->payload_hash, $attempt->payloadHash);
    }

    private function assertFreshAuthority(ProductDeletionAttempt $attempt): void
    {
        $step = DB::connection('core')->transaction(
            fn () => app(DeletionAuthority::class)->assertAttempt($attempt, lock: true),
            attempts: 3,
        );
        if ($step->product !== $this->product()) {
            throw new DeletionBlocked('deletion_attempt_stale');
        }
    }

    private function settleQueuedActivity(ProductDeletionTarget $target): void
    {
        $workspaceIds = $this->nativeWorkspaceIds($target);
        $siteIds = Site::query()->withTrashed()->whereIn('workspace_id', $workspaceIds)->pluck('id');
        IngestionBatch::query()->whereIn('site_id', $siteIds)->where('status', 'pending')->update([
            'status' => 'failed', 'failure_message' => 'The Analytics workspace is being deleted.', 'updated_at' => now(),
        ]);
        ReportExport::query()->whereIn('workspace_id', $workspaceIds)->where('status', 'pending')->update([
            'status' => 'failed', 'failure_message' => 'The Analytics workspace is being deleted.', 'updated_at' => now(),
        ]);
        ReportExport::query()->whereIn('workspace_id', $workspaceIds)->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(10))->update([
                'status' => 'failed', 'failure_message' => 'The Analytics export claim expired during workspace deletion.', 'updated_at' => now(),
            ]);
    }

    /** @return list<string> */
    private function nativeWorkspaceIds(ProductDeletionTarget $target): array
    {
        return $target->kind === 'workspace'
            ? [(string) $target->sourceId]
            : array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
    }

    private function liveActivityExists(ProductDeletionTarget $target): bool
    {
        $workspaceIds = $target->kind === 'workspace'
            ? [(string) $target->sourceId]
            : array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
        $siteIds = Site::query()->withTrashed()->whereIn('workspace_id', $workspaceIds)->pluck('id');

        return IngestionBatch::query()->whereIn('site_id', $siteIds)->where('status', 'processing')->exists()
            || ReportExport::query()->whereIn('workspace_id', $workspaceIds)->where('status', 'processing')
                ->where('updated_at', '>', now()->subMinutes(10))->exists();
    }

    private function recordExportFiles(ProductDeletionTarget $target, int $tombstoneId): void
    {
        $workspaceIds = $target->kind === 'workspace'
            ? [(string) $target->sourceId]
            : array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
        $exports = ReportExport::query()->whereIn('workspace_id', $workspaceIds)->get(['id', 'file_path']);
        $paths = $exports->pluck('file_path')->filter()->unique()->values();
        $exportPrefixes = $exports->map(fn (ReportExport $export): string => 'exports/'.$export->getKey().'-')->all();
        foreach ($exports as $export) {
            $paths->push('exports/'.$export->getKey().'.csv');
        }

        // Older retries used a timestamp in the filename and could lose the prior path.
        // Recover every such file before deleting relational export records.
        foreach (Storage::disk('analytics-local')->files('exports') as $path) {
            if (collect($exportPrefixes)->contains(fn (string $prefix): bool => str_starts_with($path, $prefix))) {
                $paths->push($path);
            }
        }

        foreach ($paths->unique() as $path) {
            DB::connection('analytics')->table('analytics_deletion_files')->insertOrIgnore([
                'tombstone_id' => $tombstoneId,
                'disk' => 'analytics-local',
                'path' => $path,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function pendingSiteDeletionExists(ProductDeletionTarget $target): bool
    {
        if (! Schema::connection('analytics')->hasTable('site_deletion_operations')) {
            return false;
        }

        return DB::connection('analytics')->table('site_deletion_operations')->where('status', '!=', 'completed')
            ->where($target->kind === 'workspace' ? 'workspace_source_id' : 'requester_source_id', (string) $target->sourceId)
            ->exists();
    }

    private function purgeWorkspace(Workspace $workspace): void
    {
        if (Schema::connection('analytics')->hasTable('site_deletion_operations')) {
            DB::connection('analytics')->table('site_deletion_operations')->where('workspace_source_id', (string) $workspace->getKey())
                ->where('status', 'completed')->delete();
        }
        if (Schema::connection('analytics')->hasTable('blueprint_application_receipts')) {
            DB::connection('analytics')->table('blueprint_application_receipts')->where('workspace_source_id', $workspace->getKey())->delete();
        }
        $siteIds = Site::query()->withTrashed()->where('workspace_id', $workspace->getKey())->pluck('id');
        DB::connection('analytics')->table('report_exports')->where('workspace_id', $workspace->getKey())->delete();
        DB::connection('analytics')->table('site_incident_annotations')->whereIn('site_id', $siteIds)->delete();
        DB::connection('analytics')->table('site_release_annotations')->whereIn('site_id', $siteIds)->delete();
        $workspace->users()->detach();
        $workspace->delete();
    }

    private function purgeAccountIdentityData(User $user): void
    {
        $connection = DB::connection('analytics');
        if (Schema::connection('analytics')->hasTable('site_deletion_operations')) {
            $connection->table('site_deletion_operations')->where('requester_source_id', (string) $user->getKey())
                ->where('status', 'completed')->delete();
        }
        if (Schema::connection('analytics')->hasTable('blueprint_application_receipts')) {
            $connection->table('blueprint_application_receipts')->where('actor_source_id', $user->getKey())->delete();
        }
        $connection->table('sessions')->where('user_id', $user->getKey())->delete();
        $connection->table('password_reset_tokens')
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $user->email))])->delete();
        $connection->table('invitations')->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $user->email))])->delete();
        $connection->table('passkeys')->where('user_id', $user->getKey())->delete();
    }

    private function upsertReceipt(ProductDeletionAttempt $attempt, string $phase, string $status, array $retained = []): void
    {
        $connection = DB::connection('analytics');
        $target = $attempt->target;
        $receipt = $connection->table('analytics_deletion_receipts')
            ->where('kind', $target->kind)->where('source_id', (string) $target->sourceId)->first();

        if ($receipt !== null) {
            if (! $this->receiptMatches($receipt, $attempt)) {
                throw new DeletionBlocked('deletion_target_binding_changed');
            }

            $connection->table('analytics_deletion_receipts')->where('id', $receipt->id)->update([
                'phase' => $phase,
                'status' => $status,
                'retained' => json_encode($retained, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return;
        }

        $connection->table('analytics_deletion_receipts')->insert([
            'kind' => $target->kind,
            'source_id' => (string) $target->sourceId,
            'request_id' => $attempt->requestId,
            'step_id' => $attempt->stepId,
            'payload_hash' => $attempt->payloadHash,
            'phase' => $phase,
            'status' => $status,
            'retained' => json_encode($retained, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertBinding(object $row, ProductDeletionAttempt $attempt): void
    {
        if ((string) $row->request_id !== $attempt->requestId
            || (string) $row->prepare_step_id !== $attempt->stepId
            || ! hash_equals((string) $row->payload_hash, $attempt->payloadHash)) {
            throw new DeletionBlocked('deletion_target_binding_changed');
        }
    }

    private function receiptMatches(object $receipt, ProductDeletionAttempt $attempt): bool
    {
        return (string) $receipt->request_id === $attempt->requestId
            && (string) $receipt->step_id === $attempt->stepId
            && hash_equals((string) $receipt->payload_hash, $attempt->payloadHash);
    }

    private function hasCompletedReceiptForTarget(ProductDeletionTarget $target): bool
    {
        $tombstone = DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', $target->kind)
            ->where('source_id', (string) $target->sourceId)
            ->where('canonical_id', $target->canonicalId)
            ->where('status', 'completed')
            ->first();
        $receipt = DB::connection('analytics')->table('analytics_deletion_receipts')
            ->where('kind', $target->kind)
            ->where('source_id', (string) $target->sourceId)
            ->where('phase', 'purge')
            ->where('status', 'completed')
            ->first();

        return $tombstone !== null && $receipt !== null
            && (string) $receipt->request_id === (string) $tombstone->request_id
            && (string) $receipt->step_id === (string) $tombstone->prepare_step_id
            && hash_equals((string) $receipt->payload_hash, (string) $tombstone->payload_hash);
    }
}
