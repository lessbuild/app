<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Jobs\GenerateReportExport;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function store(Request $request, Site $site, AnalyticsWorkspaceAccess $access): RedirectResponse
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
        $productUserId = $access->productUserIds($request->user())[0] ?? null;
        abort_if($productUserId === null, 403, 'Analytics access is not yet reconciled for this account.');
        $export = ReportExport::create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->id,
            'requested_by' => $productUserId,
            'token_hash' => hash('sha256', $token),
            'filters' => $validated,
            'expires_at' => now()->addHours(config('analytics.export_retention_hours')),
        ]);
        GenerateReportExport::dispatch($export->id)->afterCommit();

        return back()->with('status', 'Your CSV export is being generated.')->with('export_token', $token);
    }

    public function download(Request $request, string $token): StreamedResponse
    {
        $export = ReportExport::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($export->status === 'completed' && CarbonImmutable::parse($export->expires_at)->isFuture() && $export->file_path, 410, 'This export is no longer available.');
        $this->authorize('view', $export->site);
        abort_unless(Storage::disk('analytics-local')->exists($export->file_path), 404);

        return Storage::disk('analytics-local')->download($export->file_path, 'buildpusher-analytics-'.$export->site->slug.'.csv');
    }

    public function show(Request $request, string $token): View
    {
        $export = ReportExport::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->authorize('view', $export->site);

        return view('analytics::exports.show', compact('export', 'token'));
    }
}
