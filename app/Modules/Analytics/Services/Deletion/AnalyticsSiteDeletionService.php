<?php

namespace App\Modules\Analytics\Services\Deletion;

use App\Core\Data\Analytics\AnalyticsSiteDeletionOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Enums\IngestionStatus;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\SiteDeletionOperation;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AnalyticsSiteDeletionService
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsWorkspaceAccess $access,
    ) {}

    public function request(
        Authenticatable $requester,
        Site $site,
        string $confirmation,
        ?string $canonicalWorkspaceId = null,
        ?string $canonicalRequesterId = null,
    ): AnalyticsSiteDeletionOutcome {
        $nativeRequesterId = $this->nativeRequesterId($requester);
        $canonicalWorkspaceId ??= $this->identities->canonicalIdForSource('analytics', 'workspace', $site->workspace_id, 'workspace');
        $canonicalRequesterId ??= $requester instanceof PlatformUser
            ? (string) $requester->getKey()
            : $this->identities->canonicalIdForSource('analytics', 'user', $nativeRequesterId, 'user');
        $payloadHash = $this->payloadHash((string) $site->getKey(), (string) $site->workspace_id, $nativeRequesterId,
            $canonicalWorkspaceId, $canonicalRequesterId, $confirmation);

        $operationId = DB::connection('analytics')->transaction(function () use (
            $requester, $site, $confirmation, $nativeRequesterId, $canonicalWorkspaceId, $canonicalRequesterId, $payloadHash,
        ): string {
            $this->lockRow('users', $nativeRequesterId);
            $this->lockRow('workspaces', (string) $site->workspace_id);
            $lockedSite = Site::withTrashed()->where('workspace_id', $site->workspace_id)->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            $workspace = Workspace::query()->whereKey($lockedSite->workspace_id)->lockForUpdate()->firstOrFail();
            $existing = SiteDeletionOperation::query()->where('site_source_id', (string) $lockedSite->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertOperationBinding($existing, $lockedSite, $nativeRequesterId, $canonicalWorkspaceId, $canonicalRequesterId, $payloadHash);
                $this->assertOperationRequester($existing, $requester, $lockedSite, $workspace);

                return (string) $existing->getKey();
            }
            $this->assertBinding($requester, $lockedSite, $workspace, $nativeRequesterId, $confirmation);

            $lockedSite->update(['collection_enabled' => false, 'collection_paused_at' => now()]);
            $operation = SiteDeletionOperation::query()->create([
                'id' => (string) Str::ulid(),
                'site_source_id' => (string) $lockedSite->getKey(),
                'workspace_source_id' => (string) $workspace->getKey(),
                'requester_source_id' => $nativeRequesterId,
                'canonical_workspace_id' => $canonicalWorkspaceId,
                'canonical_requester_id' => $canonicalRequesterId,
                'payload_hash' => $payloadHash,
                'status' => 'fencing',
            ]);

            return (string) $operation->getKey();
        }, attempts: 3);

        return $this->advance($operationId, $requester);
    }

    public function retry(string $requestId, Authenticatable $requester): AnalyticsSiteDeletionOutcome
    {
        return $this->advance($requestId, $requester);
    }

    public function processAccepted(string $requestId): AnalyticsSiteDeletionOutcome
    {
        return $this->advance($requestId, null);
    }

    public function status(string $requestId, Authenticatable $requester): AnalyticsSiteDeletionOutcome
    {
        $operation = SiteDeletionOperation::query()->whereKey($requestId)->firstOrFail();
        $site = Site::withTrashed()->whereKey($operation->site_source_id)->firstOrFail();
        $workspace = Workspace::query()->whereKey($operation->workspace_source_id)->firstOrFail();
        $this->assertOperationRequester($operation, $requester, $site, $workspace);

        return new AnalyticsSiteDeletionOutcome(
            (string) $operation->getKey(), $operation->status, $operation->last_error_code,
        );
    }

    private function advance(string $requestId, ?Authenticatable $requester): AnalyticsSiteDeletionOutcome
    {
        $prepared = DB::connection('analytics')->transaction(function () use ($requestId, $requester): array {
            $snapshot = SiteDeletionOperation::query()->whereKey($requestId)->firstOrFail();
            $this->lockRow('users', $snapshot->requester_source_id);
            $this->lockRow('workspaces', $snapshot->workspace_source_id);
            $site = Site::withTrashed()->where('workspace_id', $snapshot->workspace_source_id)
                ->whereKey($snapshot->site_source_id)->lockForUpdate()->first();
            $workspace = Workspace::query()->whereKey($snapshot->workspace_source_id)->lockForUpdate()->first();
            $operation = SiteDeletionOperation::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            abort_unless($operation->requester_source_id === $snapshot->requester_source_id
                && $operation->workspace_source_id === $snapshot->workspace_source_id
                && $operation->site_source_id === $snapshot->site_source_id, 409);
            if ($operation->status === 'completed') {
                if ($requester !== null && $site !== null && $workspace !== null) {
                    $this->assertOperationRequester($operation, $requester, $site, $workspace);
                } elseif ($requester !== null) {
                    abort(404);
                }

                return ['status' => 'completed', 'reason' => null, 'manifest' => []];
            }
            if ($site === null || $workspace === null) {
                $operation->forceFill(['status' => 'blocked', 'last_error_code' => 'native_target_missing'])->save();

                return ['status' => 'blocked', 'reason' => 'native_target_missing', 'manifest' => []];
            }
            if ($requester !== null) {
                $this->assertOperationRequester($operation, $requester, $site, $workspace);
            }
            $site->update(['collection_enabled' => false, 'collection_paused_at' => $site->collection_paused_at ?? now()]);
            IngestionBatch::query()->where('site_id', $site->getKey())
                ->whereIn('status', [IngestionStatus::Pending->value, IngestionStatus::Processing->value])
                ->update(['status' => IngestionStatus::Failed->value, 'failure_message' => 'The Analytics site is being deleted.', 'updated_at' => now()]);
            ReportExport::query()->where('site_id', $site->getKey())->whereIn('status', ['pending', 'processing'])->update([
                'status' => 'failed', 'failure_message' => 'The Analytics site is being deleted.', 'updated_at' => now(),
            ]);

            try {
                $manifest = $operation->manifest_at === null
                    ? $this->manifestFor($site, $operation->file_manifest ?? [])
                    : ($operation->file_manifest ?? []);
            } catch (\Throwable) {
                $operation->forceFill(['status' => 'waiting', 'last_error_code' => 'export_manifest_unavailable'])->save();

                return ['status' => 'waiting', 'reason' => 'export_manifest_unavailable', 'manifest' => []];
            }
            $operation->forceFill([
                'status' => 'fencing', 'last_error_code' => null,
                'file_manifest' => $manifest, 'manifest_at' => $operation->manifest_at ?? now(),
            ])->save();

            return ['status' => 'removing_files', 'reason' => null, 'manifest' => $manifest];
        }, attempts: 3);

        if ($prepared['status'] === 'completed' || $prepared['status'] === 'blocked' || $prepared['status'] === 'waiting') {
            return new AnalyticsSiteDeletionOutcome($requestId, $prepared['status'], $prepared['reason']);
        }

        try {
            foreach ($prepared['manifest'] as $path) {
                Storage::disk('analytics-local')->delete($path);
                if (Storage::disk('analytics-local')->exists($path)) {
                    return $this->markWaiting($requestId, 'export_file_cleanup_pending');
                }
            }
        } catch (\Throwable) {
            return $this->markWaiting($requestId, 'export_file_cleanup_pending');
        }

        return DB::connection('analytics')->transaction(function () use ($requestId, $requester): AnalyticsSiteDeletionOutcome {
            $snapshot = SiteDeletionOperation::query()->whereKey($requestId)->firstOrFail();
            $this->lockRow('users', $snapshot->requester_source_id);
            $this->lockRow('workspaces', $snapshot->workspace_source_id);
            $site = Site::withTrashed()->where('workspace_id', $snapshot->workspace_source_id)
                ->whereKey($snapshot->site_source_id)->lockForUpdate()->first();
            $workspace = Workspace::query()->whereKey($snapshot->workspace_source_id)->lockForUpdate()->first();
            $operation = SiteDeletionOperation::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            abort_unless($operation->requester_source_id === $snapshot->requester_source_id
                && $operation->workspace_source_id === $snapshot->workspace_source_id
                && $operation->site_source_id === $snapshot->site_source_id, 409);
            if ($operation->status === 'completed') {
                if ($requester !== null && $site !== null && $workspace !== null) {
                    $this->assertOperationRequester($operation, $requester, $site, $workspace);
                } elseif ($requester !== null) {
                    abort(404);
                }

                return new AnalyticsSiteDeletionOutcome($requestId, 'completed');
            }
            if ($site === null) {
                return $this->markWaiting($requestId, 'native_target_missing');
            }
            if ($requester !== null) {
                abort_unless($workspace !== null, 404);
                $this->assertOperationRequester($operation, $requester, $site, $workspace);
            }
            foreach ($operation->file_manifest ?? [] as $path) {
                if (Storage::disk('analytics-local')->exists($path)) {
                    return $this->markWaiting($requestId, 'export_file_cleanup_pending');
                }
            }

            ReportExport::query()->where('site_id', $site->getKey())->delete();
            $site->goalConversions()->delete();
            $site->events()->delete();
            $site->releaseAnnotations()->delete();
            $site->incidentAnnotations()->delete();
            $site->visits()->delete();
            DB::connection('analytics')->table('report_daily_aggregates')->where('site_id', $site->getKey())->delete();
            $site->goals()->delete();
            $site->ingestionBatches()->delete();
            if (! $site->trashed()) {
                $site->delete();
            }
            $operation->forceFill(['status' => 'completed', 'last_error_code' => null, 'completed_at' => now()])->save();

            return new AnalyticsSiteDeletionOutcome($requestId, 'completed');
        }, attempts: 3);
    }

    /** @return list<string> */
    private function manifestFor(Site $site, array $existing): array
    {
        $exports = ReportExport::query()->where('site_id', $site->getKey())->get(['id', 'file_path']);
        $disk = Storage::disk('analytics-local');
        $files = $disk->allFiles('exports');
        $paths = [];
        $allowedPaths = [];
        foreach ($exports as $export) {
            $id = (string) $export->getKey();
            $deterministicPath = 'exports/'.$id.'.csv';
            $legacyPattern = '/^exports\/'.preg_quote($id, '/').'(?:-[A-Za-z0-9_.-]+|_[A-Za-z0-9_.-]+|\.[A-Za-z0-9_.-]+|\/[^\/]+)$/';
            $ownedPath = static fn (string $path): bool => $path === $deterministicPath || preg_match($legacyPattern, $path) === 1;
            if (filled($export->file_path)) {
                $persistedPath = (string) $export->file_path;
                if (! $ownedPath($persistedPath)) {
                    throw new \UnexpectedValueException('Report export file path is outside its export namespace.');
                }
                $paths[] = $persistedPath;
                $allowedPaths[] = $persistedPath;
            }
            // Current deterministic output and the previous export-name convention
            // cover a process dying after Storage::put but before file_path commit.
            $paths[] = $deterministicPath;
            foreach ($files as $candidate) {
                if ($ownedPath($candidate)) {
                    $paths[] = $candidate;
                }
            }
            $allowedPaths[] = $deterministicPath;
            foreach ($existing as $existingPath) {
                if (is_string($existingPath) && $ownedPath($existingPath)) {
                    $allowedPaths[] = $existingPath;
                }
            }
            foreach ($files as $candidate) {
                if ($ownedPath($candidate)) {
                    $allowedPaths[] = $candidate;
                }
            }
        }

        foreach ($existing as $path) {
            if (! is_string($path) || ! in_array($path, $allowedPaths, true)) {
                throw new \UnexpectedValueException('Persisted deletion manifest contains a path outside its export namespace.');
            }
            $paths[] = $path;
        }

        return collect($paths)->unique()->sort()->values()->all();
    }

    private function assertBinding(Authenticatable $requester, Site $site, Workspace $workspace, string $nativeRequesterId, string $confirmation): void
    {
        abort_unless((string) $site->workspace_id === (string) $workspace->getKey(), 404);
        abort_unless(hash_equals((string) $site->slug, trim($confirmation)), 422, 'Confirm the exact Analytics site slug to delete it.');
        abort_unless((string) $requester->getAuthIdentifier() === $nativeRequesterId
            || $requester instanceof PlatformUser, 403);
        abort_unless(app(SitePolicy::class)->delete($requester, $site), 403);
    }

    private function assertOperationRequester(SiteDeletionOperation $operation, Authenticatable $requester, Site $site, Workspace $workspace): void
    {
        $nativeRequesterId = $this->nativeRequesterId($requester);
        $canonicalRequesterId = $requester instanceof PlatformUser
            ? (string) $requester->getKey()
            : $this->identities->canonicalIdForSource('analytics', 'user', $nativeRequesterId, 'user');
        $canonicalWorkspaceId = $this->identities->canonicalIdForSource('analytics', 'workspace', $workspace->getKey(), 'workspace');
        abort_unless($operation->requester_source_id === $nativeRequesterId
            && $operation->site_source_id === (string) $site->getKey()
            && $operation->workspace_source_id === (string) $workspace->getKey()
            && $operation->canonical_requester_id === $canonicalRequesterId
            && $operation->canonical_workspace_id === $canonicalWorkspaceId
            && $this->access->hasAccess($requester, $workspace)
            && $this->access->roleFor($requester, $workspace)?->value === 'owner', 403);
    }

    private function assertOperationBinding(SiteDeletionOperation $operation, Site $site, string $nativeRequesterId, ?string $canonicalWorkspaceId, ?string $canonicalRequesterId, string $payloadHash): void
    {
        abort_unless($operation->site_source_id === (string) $site->getKey()
            && $operation->workspace_source_id === (string) $site->workspace_id
            && $operation->requester_source_id === $nativeRequesterId
            && $operation->canonical_workspace_id === $canonicalWorkspaceId
            && $operation->canonical_requester_id === $canonicalRequesterId
            && hash_equals($operation->payload_hash, $payloadHash), 409, 'This site already has a different accepted deletion request.');
    }

    private function nativeRequesterId(Authenticatable $requester): string
    {
        if ($requester instanceof AnalyticsUser) {
            return (string) $requester->getKey();
        }
        $ids = $requester instanceof PlatformUser ? $this->identities->sourceIdsFor($requester, 'analytics') : [];
        abort_unless(count($ids) === 1, 404);

        return (string) $ids[0];
    }

    private function payloadHash(string $siteId, string $workspaceId, string $requesterId, ?string $canonicalWorkspaceId, ?string $canonicalRequesterId, string $confirmation): string
    {
        return hash('sha256', json_encode([
            'product' => 'analytics', 'site' => $siteId, 'workspace_source_id' => $workspaceId,
            'requester_source_id' => $requesterId, 'canonical_workspace_id' => $canonicalWorkspaceId,
            'canonical_requester_id' => $canonicalRequesterId, 'confirmation' => trim($confirmation),
        ], JSON_THROW_ON_ERROR));
    }

    private function lockRow(string $table, string $id): void
    {
        DB::connection('analytics')->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        DB::connection('analytics')->table($table)->where('id', $id)->lockForUpdate()->first();
    }

    private function markWaiting(string $requestId, string $reason): AnalyticsSiteDeletionOutcome
    {
        SiteDeletionOperation::query()->whereKey($requestId)->where('status', '!=', 'completed')
            ->update(['status' => 'waiting', 'last_error_code' => $reason, 'updated_at' => now()]);
        $current = SiteDeletionOperation::query()->whereKey($requestId)->first();

        return new AnalyticsSiteDeletionOutcome(
            $requestId,
            $current?->status ?? 'blocked',
            $current?->status === 'completed' ? null : $reason,
        );
    }
}
