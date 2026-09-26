<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Jobs\GenerateReportExport;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function store(Request $request, Site $site, AnalyticsWorkspaceAccess $access, AnalyticsDeletionFence $deletionFence): RedirectResponse
    {
        $this->authorize('manage', $site);
        $validated = $request->validate([
            'days' => ['required', 'integer', 'in:7,30,90,365'],
            'path' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:150'],
            'device' => ['nullable', 'string', 'max:32'],
        ]);
        $token = Str::random(64);
        $productUserId = $this->requestingProductUserId($request, $site, $access);
        abort_if($productUserId === null, 403, 'Analytics access is not yet reconciled for this account.');
        $export = DB::connection('analytics')->transaction(function () use ($site, $productUserId, $token, $validated, $deletionFence): ReportExport {
            $workspace = $site->workspace()->lockForUpdate()->firstOrFail();
            $deletionFence->assertWorkspaceOpen($workspace->getKey());
            $lockedSite = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            $deletionFence->assertSiteOpen($lockedSite->getKey());
            $this->authorize('manage', $lockedSite);

            return ReportExport::create([
                'workspace_id' => $workspace->getKey(),
                'site_id' => $lockedSite->getKey(),
                'requested_by' => $productUserId,
                'token_hash' => hash('sha256', $token),
                'filters' => $validated,
                'expires_at' => now()->addHours(config('analytics.export_retention_hours')),
            ]);
        }, attempts: 3);
        GenerateReportExport::dispatch($export->id, (int) $export->generation)->afterCommit();

        return back()->with('status', 'Your CSV export is being generated.')->with('export_token', $token);
    }

    public function download(Request $request, string $token, AnalyticsDeletionFence $deletionFence): StreamedResponse
    {
        $export = ReportExport::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->authorize('view', $export->site);
        $deletionFence->assertWorkspaceOpen($export->workspace_id);
        $deletionFence->assertSiteOpen($export->site_id);

        return $this->downloadExport($export);
    }

    public function show(Request $request, string $token, AnalyticsDeletionFence $deletionFence): View
    {
        $export = ReportExport::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->authorize('view', $export->site);
        $deletionFence->assertWorkspaceOpen($export->workspace_id);
        $deletionFence->assertSiteOpen($export->site_id);

        return view('analytics::exports.show', [
            'export' => $export,
            'token' => $token,
            'downloadAvailable' => $this->downloadAvailable($export),
        ]);
    }

    public function record(Request $request, Site $site, ReportExport $export, AnalyticsDeletionFence $deletionFence): View
    {
        $this->assertExportBelongsToSite($export, $site);
        $this->authorize('view', $site);
        $deletionFence->assertWorkspaceOpen($site->workspace_id);
        $deletionFence->assertSiteOpen($site->getKey());

        return view('analytics::exports.record', [
            'export' => $export,
            'site' => $site,
            'downloadAvailable' => $this->downloadAvailable($export),
            'canRetry' => $request->user()?->can('manage', $site) ?? false,
        ]);
    }

    public function downloadRecord(Request $request, Site $site, ReportExport $export, AnalyticsDeletionFence $deletionFence): StreamedResponse
    {
        $this->assertExportBelongsToSite($export, $site);
        $this->authorize('view', $site);
        $deletionFence->assertWorkspaceOpen($site->workspace_id);
        $deletionFence->assertSiteOpen($site->getKey());

        return $this->downloadExport($export);
    }

    public function retry(Request $request, Site $site, ReportExport $export, AnalyticsWorkspaceAccess $access, AnalyticsDeletionFence $deletionFence): RedirectResponse
    {
        $this->assertExportBelongsToSite($export, $site);
        $this->authorize('manage', $site);
        $productUserId = $this->requestingProductUserId($request, $site, $access);
        abort_if($productUserId === null, 403, 'Analytics access is not yet reconciled for this account.');

        $updatedExport = DB::connection('analytics')->transaction(function () use ($site, $export, $productUserId, $deletionFence): ReportExport {
            $workspace = $site->workspace()->lockForUpdate()->firstOrFail();
            $deletionFence->assertWorkspaceOpen($workspace->getKey());
            $lockedSite = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            $deletionFence->assertSiteOpen($lockedSite->getKey());
            $this->authorize('manage', $lockedSite);
            $lockedExport = ReportExport::query()
                ->whereKey($export->getKey())
                ->where('site_id', $site->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedExport->status === 'failed', 409, 'Only failed exports can be retried.');
            abort_unless($lockedExport->expires_at !== null && CarbonImmutable::parse($lockedExport->expires_at)->isFuture(), 410, 'This export has expired.');

            $lockedExport->update([
                'status' => 'pending',
                'requested_by' => $productUserId,
                'failure_message' => null,
                'file_path' => null,
                'completed_at' => null,
                'expires_at' => now()->addHours(config('analytics.export_retention_hours')),
                'generation' => (int) $lockedExport->generation + 1,
            ]);

            return $lockedExport;
        });

        GenerateReportExport::dispatch($updatedExport->getKey(), (int) $updatedExport->generation)->afterCommit();

        return back()->with('status', 'The CSV export has been queued again.');
    }

    private function assertExportBelongsToSite(ReportExport $export, Site $site): void
    {
        abort_unless((string) $export->site_id === (string) $site->getKey(), 404);
        abort_unless((string) $export->workspace_id === (string) $site->workspace_id, 404);
    }

    private function requestingProductUserId(Request $request, Site $site, AnalyticsWorkspaceAccess $access): string|int|null
    {
        return $site->workspace->users()
            ->whereIn('users.id', $access->productUserIds($request->user()))
            ->orderBy('users.id')
            ->value('users.id');
    }

    private function downloadExport(ReportExport $export): StreamedResponse
    {
        abort_unless($this->downloadAvailable($export), 410, 'This export is no longer available.');

        return Storage::disk('analytics-local')->download(
            $export->file_path,
            'buildpusher-analytics-'.$export->site->slug.'.csv',
        );
    }

    private function downloadAvailable(ReportExport $export): bool
    {
        return $export->status === 'completed'
            && $export->expires_at !== null
            && CarbonImmutable::parse($export->expires_at)->isFuture()
            && filled($export->file_path)
            && Storage::disk('analytics-local')->exists($export->file_path);
    }
}
