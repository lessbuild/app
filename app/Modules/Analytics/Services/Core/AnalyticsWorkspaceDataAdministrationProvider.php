<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\Analytics\WorkspaceAnalyticsDataAdministrationProvider;
use App\Core\Data\Analytics\AnalyticsDataSnapshot;
use App\Core\Data\Analytics\AnalyticsProcessingSummary;
use App\Core\Data\Analytics\AnalyticsReportSummary;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Jobs\GenerateReportExport;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use App\Modules\Analytics\Services\ExportWorkspaceData;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AnalyticsWorkspaceDataAdministrationProvider implements WorkspaceAnalyticsDataAdministrationProvider
{
    public function __construct(
        private readonly AnalyticsCoreAdministrationAccess $nativeAccess,
        private readonly AnalyticsWorkspaceAccess $access,
        private readonly AnalyticsPlanAuthority $plans,
        private readonly ExportWorkspaceData $workspaceExport,
    ) {}

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, ?string $siteId = null): AnalyticsDataSnapshot
    {
        try {
            $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
            $plan = $this->plans->resolve($nativeWorkspace);
            $exportPlanAvailable = $plan->available && $plan->allows('site_management') && $plan->hasLimit('export_retention_hours');
            $siteQuery = $this->nativeAccess->sites($user, $nativeWorkspace);
            if ($siteId !== null) {
                $siteQuery->whereKey($siteId);
            }
            $sites = $siteQuery->orderBy('id')->limit(100)->get(['id', 'workspace_id', 'name']);
            if ($siteId !== null && $sites->isEmpty()) {
                abort(404);
            }
            $siteIds = $sites->modelKeys();
            $reports = ReportExport::query()->where('workspace_id', $nativeWorkspace->getKey())
                ->whereIn('site_id', $siteIds)->with('site:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (ReportExport $report): AnalyticsReportSummary => $this->reportSummary(
                    $user,
                    $report,
                    $exportPlanAvailable ? $plan->limit('export_retention_hours') : null,
                    $exportPlanAvailable,
                ))->all();
            $processing = IngestionBatch::query()->whereIn('site_id', $siteIds)->with('site:id,name')
                ->orderByDesc('accepted_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (IngestionBatch $batch): AnalyticsProcessingSummary => new AnalyticsProcessingSummary(
                    siteId: (string) $batch->site_id, siteName: (string) ($batch->site?->name ?? ''),
                    status: $this->safeStatus((string) $batch->status), acceptedEventCount: (int) $batch->event_count,
                    acceptedAt: CarbonImmutable::parse($batch->accepted_at)->utc(),
                    processedAt: $batch->processed_at?->toImmutable()->utc(),
                ))->all();
            $role = $this->nativeAccess->role($user, $nativeWorkspace);

            return new AnalyticsDataSnapshot($reports, $processing, canExportWorkspace: $role?->canManageMembers() === true);
        } catch (LostConnectionException|PDOException) {
            return new AnalyticsDataSnapshot([], [], available: false);
        }
    }

    public function requestReport(PlatformUser $user, CoreWorkspace $coreWorkspace, string $siteId, array $filters): void
    {
        $filters = $this->validatedFilters($filters);
        $nativeWorkspace = $this->nativeAccess->workspace($user, $coreWorkspace);
        $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId, manage: true);
        $requesterId = $this->requesterId($user, $nativeWorkspace);
        $exportId = DB::connection('analytics')->transaction(function () use ($user, $coreWorkspace, $nativeWorkspace, $site, $requesterId, $filters): string {
            $workspace = Workspace::query()->whereKey($nativeWorkspace->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->nativeAccess->workspace($user, $coreWorkspace);
            abort_unless((string) $current->getKey() === (string) $workspace->getKey(), 404);
            abort_unless($this->nativeAccess->role($user, $workspace)?->canManageSites() === true, 403);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($workspace->getKey());
            $plan = $this->assertExportPlan($workspace);
            $lockedSite = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            app(AnalyticsDeletionFence::class)->assertSiteOpen($lockedSite->getKey());
            $this->nativeAccess->site($user, $workspace, (string) $lockedSite->getKey(), manage: true);
            $export = ReportExport::query()->create([
                'workspace_id' => $workspace->getKey(), 'site_id' => $lockedSite->getKey(), 'requested_by' => $requesterId,
                'token_hash' => hash('sha256', Str::random(64)), 'filters' => $filters,
                'expires_at' => $this->exportExpiry($plan->limit('export_retention_hours')),
            ]);

            return (string) $export->getKey();
        }, attempts: 3);
        $generation = (int) ReportExport::query()->whereKey($exportId)->value('generation');
        GenerateReportExport::dispatch($exportId, $generation)->afterCommit();
    }

    public function retryReport(PlatformUser $user, CoreWorkspace $coreWorkspace, string $siteId, string $exportId): void
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $coreWorkspace);
        $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId, manage: true);
        $requesterId = $this->requesterId($user, $nativeWorkspace);
        DB::connection('analytics')->transaction(function () use ($user, $coreWorkspace, $nativeWorkspace, $site, $exportId, $requesterId): void {
            $workspace = Workspace::query()->whereKey($nativeWorkspace->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->nativeAccess->workspace($user, $coreWorkspace);
            abort_unless((string) $current->getKey() === (string) $workspace->getKey(), 404);
            abort_unless($this->nativeAccess->role($user, $workspace)?->canManageSites() === true, 403);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($workspace->getKey());
            $plan = $this->assertExportPlan($workspace);
            $lockedSite = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            app(AnalyticsDeletionFence::class)->assertSiteOpen($lockedSite->getKey());
            $this->nativeAccess->site($user, $workspace, (string) $lockedSite->getKey(), manage: true);
            $export = ReportExport::query()->where('workspace_id', $workspace->getKey())->where('site_id', $lockedSite->getKey())->whereKey($exportId)->lockForUpdate()->firstOrFail();
            abort_unless($export->status === 'failed', 409);
            abort_unless($this->exportIsAvailable($export, $plan->limit('export_retention_hours')), 410);
            if (filled($export->file_path)) {
                Storage::disk('analytics-local')->delete($export->file_path);
                if (Storage::disk('analytics-local')->exists($export->file_path)) {
                    throw new RuntimeException('The previous Analytics export file could not be replaced.');
                }
            }
            $export->update([
                'status' => 'pending', 'requested_by' => $requesterId, 'failure_message' => null,
                'file_path' => null, 'completed_at' => null,
                'expires_at' => $this->exportExpiry($plan->limit('export_retention_hours')),
                'generation' => (int) $export->generation + 1,
            ]);
            GenerateReportExport::dispatch($export->getKey(), (int) $export->generation)->afterCommit();
        }, attempts: 3);
    }

    public function downloadReport(PlatformUser $user, CoreWorkspace $coreWorkspace, string $siteId, string $exportId): StreamedResponse
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $coreWorkspace);
        $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId);
        $plan = $this->assertExportPlan($nativeWorkspace);
        $export = ReportExport::query()->where('workspace_id', $nativeWorkspace->getKey())
            ->where('site_id', $site->getKey())->whereKey($exportId)->firstOrFail();
        abort_unless($export->status === 'completed'
            && $this->exportIsAvailable($export, $plan->limit('export_retention_hours')) && filled($export->file_path)
            && Storage::disk('analytics-local')->exists($export->file_path), 410);
        app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($nativeWorkspace->getKey());
        app(AnalyticsDeletionFence::class)->assertSiteOpen($site->getKey());

        return Storage::disk('analytics-local')->download($export->file_path, 'buildpusher-analytics-'.$site->slug.'.csv', [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function workspaceExport(PlatformUser $user, CoreWorkspace $coreWorkspace): StreamedResponse
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $coreWorkspace);
        abort_unless($this->nativeAccess->role($user, $nativeWorkspace)?->canManageMembers() === true, 403);
        $this->access->hasAccess($user, $nativeWorkspace) || abort(404);

        return response()->streamDownload(function () use ($user, $nativeWorkspace): void {
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($nativeWorkspace->getKey());
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                abort(500);
            }
            try {
                $this->workspaceExport->write($nativeWorkspace, $output, $user);
            } finally {
                fclose($output);
            }
        }, 'buildpusher-analytics-'.Str::slug($nativeWorkspace->slug).'-'.now('UTC')->format('Ymd-His').'.ndjson', [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function reportSummary(PlatformUser $user, ReportExport $export, ?int $retentionHours, bool $planAvailable): AnalyticsReportSummary
    {
        $canManage = $export->site !== null && app(SitePolicy::class)->manage($user, $export->site);
        $available = $planAvailable && $export->status === 'completed'
            && $this->exportIsAvailable($export, $retentionHours) && filled($export->file_path)
            && Storage::disk('analytics-local')->exists($export->file_path);
        $filters = collect($export->filters ?? [])->only(['days', 'path', 'source', 'campaign', 'device'])
            ->filter(fn ($value): bool => is_string($value) || is_numeric($value))
            ->map(fn ($value): string => mb_substr((string) $value, 0, 255))->all();

        return new AnalyticsReportSummary(
            id: (string) $export->getKey(), siteId: (string) $export->site_id,
            siteName: (string) ($export->site?->name ?? ''), status: $this->safeStatus((string) $export->status),
            filters: $filters, createdAt: $export->created_at->toImmutable()->utc(),
            expiresAt: $this->effectiveExportExpiry($export, $retentionHours)?->utc(), downloadAvailable: $available,
            canRetry: $planAvailable && $canManage && $export->status === 'failed'
                && $this->exportIsAvailable($export, $retentionHours),
        );
    }

    private function assertExportPlan(Workspace $workspace): ProductPlanResolution
    {
        $plan = $this->plans->resolve($workspace);
        abort_unless($plan->available && $plan->allows('site_management') && $plan->hasLimit('export_retention_hours'), 503);

        return $plan;
    }

    private function exportExpiry(?int $retentionHours): ?CarbonImmutable
    {
        return $retentionHours === null ? null : CarbonImmutable::now()->addHours(max(1, $retentionHours));
    }

    private function effectiveExportExpiry(ReportExport $export, ?int $retentionHours): ?CarbonImmutable
    {
        if ($retentionHours === null) {
            return $export->expires_at?->toImmutable();
        }

        $planExpiry = CarbonImmutable::parse($export->updated_at ?? $export->created_at)->addHours(max(1, $retentionHours));
        if ($export->expires_at === null) {
            return $planExpiry;
        }

        // A plan upgrade must never revive an export whose stored expiry has passed.
        return $export->expires_at->toImmutable()->min($planExpiry);
    }

    private function exportIsAvailable(ReportExport $export, ?int $retentionHours): bool
    {
        $expiresAt = $this->effectiveExportExpiry($export, $retentionHours);

        return $expiresAt === null || $expiresAt->isFuture();
    }

    private function requesterId(PlatformUser $user, Workspace $workspace): string|int
    {
        $ids = $this->access->productUserIds($user);
        $id = $workspace->users()->whereIn('users.id', $ids)->orderBy('users.id')->value('users.id');
        abort_if($id === null, 403);

        return $id;
    }

    private function safeStatus(string $status): string
    {
        return in_array($status, ['pending', 'processing', 'processed', 'completed', 'failed'], true)
            ? $status
            : 'unavailable';
    }

    /** @param array<string, mixed> $filters @return array{days: int, path: ?string, source: ?string, campaign: ?string, device: ?string} */
    private function validatedFilters(array $filters): array
    {
        $days = filter_var($filters['days'] ?? null, FILTER_VALIDATE_INT);
        abort_unless(in_array($days, [7, 30, 90, 365], true), 422);
        $limits = ['path' => 2048, 'source' => 255, 'campaign' => 150, 'device' => 32];
        $validated = ['days' => $days];
        foreach ($limits as $key => $max) {
            $value = $filters[$key] ?? null;
            abort_unless($value === null || (is_string($value) && mb_strlen($value) <= $max), 422);
            $validated[$key] = $value === null || trim($value) === '' ? null : trim($value);
        }

        return $validated;
    }
}
