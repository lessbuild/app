<?php

namespace App\Http\Controllers;

use App\Actions\Web\RetryWebsiteProvisioningAction;
use App\Actions\Web\UpdateWebsiteAction;
use App\Http\Requests\WebsiteRequest;
use App\Http\Responses\PlainTextLogDownload;
use App\Jobs\Web\AddWebsiteJob;
use App\Jobs\Web\CheckWebsiteHealthJob;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Jobs\Web\RefreshWebsiteLogJob;
use App\Models\Server;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use App\Models\WebsiteLogSnapshot;
use App\Services\Entitlements;
use App\Services\PlanLimits;
use App\Services\WebsiteHealthHistoryExporter;
use App\Services\WebsiteHealthHistoryQuery;
use App\Services\WebsiteInventoryExporter;
use App\Services\WebsiteInventoryQuery;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsitesController extends Controller
{
    /**
     * Use workspace entitlements to guard website features that depend on a paid plan.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly WebsiteHealthHistoryExporter $healthHistoryExporter,
        private readonly WebsiteHealthHistoryQuery $healthHistory,
        private readonly WebsiteInventoryExporter $websiteInventoryExporter,
        private readonly WebsiteInventoryQuery $websiteInventory,
        private readonly UpdateWebsiteAction $updateWebsite,
    ) {}

    /**
     * List all created websites for the user
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $websites = $this->websiteInventory->for($request->user(), $filters)
            ->with('server')
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.websites.index', [
            'websites' => $websites,
            'filters' => $filters,
            'metrics' => $this->websiteInventory->metrics($request->user(), $filters),
            'statuses' => $this->websiteStatuses(),
            'healthStatuses' => ['disabled', Website::HEALTH_UNKNOWN, Website::HEALTH_HEALTHY, Website::HEALTH_UNHEALTHY],
        ]);
    }

    /**
     * Stream filtered workspace website inventory, monitoring settings, and placement metadata as private CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);

        return $this->websiteInventoryExporter->stream($request->user(), $filters);
    }

    /**
     * Show the specified websites
     */
    public function show(Website $website): View
    {
        $this->authorize('view', $website);

        $repositories = $website->repositories()->with('latestBuild')->latest()->paginate();
        $retainedHealthChecks = $this->healthHistory->retained($website);

        return view('scenes.websites.show', [
            'website' => $website,
            'repositories' => $repositories,
            'healthChecks' => $retainedHealthChecks->take(20),
            'healthMetrics' => $this->healthHistory->metrics($retainedHealthChecks),
            'runtimeLogs' => $website->runtimeLogs()->get()->keyBy('type'),
        ]);
    }

    /**
     * Authorize the website and validate the requested log type before queuing an active website's snapshot.
     *
     * @return RedirectResponse A queued result or an explanation that provisioning is unfinished.
     */
    public function refreshRuntimeLog(Website $website, string $type): RedirectResponse
    {
        $this->authorize('update', $website);
        abort_unless(in_array($type, WebsiteLogSnapshot::TYPES, true), 404);
        if ($website->provisioning_status !== Website::STATUS_ACTIVE) {
            return back()->with('info', __('Runtime logs are available after website provisioning completes.'));
        }
        $website->runtimeLogs()->updateOrCreate(['type' => $type], [
            'status' => WebsiteLogSnapshot::STATUS_QUEUED,
            'error' => null,
        ]);
        RefreshWebsiteLogJob::dispatch($website->id, $type);

        return back()->with('success', __('Runtime log refresh queued.'));
    }

    /**
     * Authorize website visibility and return an uncached snapshot for a supported runtime log type.
     *
     * Missing snapshots return idle status and empty log text; unsupported types return 404.
     */
    public function runtimeLog(Website $website, string $type): JsonResponse
    {
        $this->authorize('view', $website);
        abort_unless(in_array($type, WebsiteLogSnapshot::TYPES, true), 404);
        $snapshot = $website->runtimeLogs()->where('type', $type)->first();

        return response()->json([
            'status' => $snapshot?->status ?? 'idle',
            'log' => $snapshot?->log ?? '',
            'error' => $snapshot?->error,
            'refreshed_at' => $snapshot?->refreshed_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Validate a supported retained-line count for an editable website and redirect after saving future-snapshot settings.
     */
    public function updateLogRetention(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'log_retention_lines' => ['required', 'integer', Rule::in([100, 500, 1000, 5000, 10000])],
        ]);
        $website->update($data);

        return back()->with('success', __('Log retention updated. Future snapshots will keep the selected number of lines.'));
    }

    /**
     * Authorize website visibility and render filtered, paginated check history with matching aggregate metrics.
     */
    public function healthChecks(Request $request, Website $website): View
    {
        $this->authorize('view', $website);
        $filters = $this->healthCheckFilters($request);

        return view('scenes.websites.health-checks', [
            'website' => $website,
            'healthChecks' => $this->healthHistory->for($website, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->healthHistory->filteredMetrics($website, $filters),
            'sources' => [WebsiteHealthCheck::SOURCE_MANUAL, WebsiteHealthCheck::SOURCE_AUTOMATIC],
        ]);
    }

    /**
     * Authorize website visibility and stream bounded, filtered health-check history as private, spreadsheet-safe CSV.
     */
    public function exportHealthChecks(Request $request, Website $website): StreamedResponse
    {
        $this->authorize('view', $website);
        $filters = $this->healthCheckFilters($request);

        return $this->healthHistoryExporter->stream($website, $filters);
    }

    /** @return array{result: ?string, source: ?string, date_from: ?string, date_to: ?string} */
    private function healthCheckFilters(Request $request): array
    {
        $result = $request->string('result')->toString();
        $source = $request->string('source')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return [
            'result' => in_array($result, ['healthy', 'failed'], true) ? $result : null,
            'source' => in_array($source, [
                WebsiteHealthCheck::SOURCE_MANUAL,
                WebsiteHealthCheck::SOURCE_AUTOMATIC,
            ], true) ? $source : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * Return an unchanged valid Y-m-d calendar date, or null for malformed or overflowing input.
     */
    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Show the resource creation form
     */
    public function create(Request $request, PlanLimits $limits): View
    {
        $servers = $request->user()->workspaceServers()->readyForWebsites()->get();

        return view('scenes.websites.create', [
            'servers' => $servers,
            'planUsage' => $limits->usage($request->user(), 'websites'),
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    public function store(WebsiteRequest $request, PlanLimits $limits): RedirectResponse
    {
        $validated = $request->validated();
        if ($validated['health_monitoring_enabled']) {
            $this->entitlements->enforce($request->user()->currentOrganization, 'monitoring');
        }

        $password = Str::random(32);
        $website = $limits->withinLimit(
            $request->user(),
            'websites',
            fn ($organization) => $organization->websites()->create(array_merge($validated, [
                'user_id' => $request->user()->id,
                'database_password' => $password,
                'provisioning_status' => Website::STATUS_QUEUED,
            ])),
        );
        session()->flash("website:{$website->id}:mysql_password", $password);
        AddWebsiteJob::dispatch($website);

        return redirect()->route('websites.show', $website);
    }

    /**
     * Show the resource edit form
     */
    public function edit(Request $request, Website $website): View
    {
        $this->authorize('update', $website);

        $servers = $request->user()->workspaceServers()->readyForWebsites()->get();

        return view('scenes.websites.edit', [
            'servers' => $servers,
            'website' => $website,
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    public function update(WebsiteRequest $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $validated = $request->validated();
        if ($validated['health_monitoring_enabled']) {
            $this->entitlements->enforce($request->user()->currentOrganization, 'monitoring');
        }
        $this->updateWebsite->handle($website, $validated);

        return redirect()->route('websites.show', $website);
    }

    /**
     * Authorize website updates and queue cleanup of its previous server placement when one remains.
     */
    public function retryPlacementCleanup(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        if (! $website->previous_server_id) {
            return back()->with('info', __('There is no previous website placement to clean up.'));
        }

        $website->update(['placement_cleanup_error' => null]);
        CleanupWebsitePlacementJob::dispatch(
            $website->id,
            $website->previous_server_id,
            $website->deployment_slug,
        );

        return back()->with('success', __('Previous server cleanup queued.'));
    }

    /**
     * Authorize website updates and request a failed-provisioning retry, redirecting with its eligibility result.
     */
    public function retryProvisioning(
        Website $website,
        RetryWebsiteProvisioningAction $retry,
    ): RedirectResponse {
        $this->authorize('update', $website);

        if (! $retry->handle($website)) {
            return back()->with('info', __('Website provisioning is no longer in a failed state.'));
        }

        return back()->with('success', __('Website provisioning retry queued.'));
    }

    /**
     * Authorize website updates and queue a manual check only when monitoring is enabled and both website and server are active.
     */
    public function checkHealth(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $website->loadMissing('server');

        if (! $website->health_check_enabled) {
            return back()->with('info', __('Enable health checks before requesting a manual check.'));
        }

        if ($website->provisioning_status !== Website::STATUS_ACTIVE
            || $website->server?->provisioning_status !== Server::STATUS_ACTIVE) {
            return back()->with('info', __('The website and its server must be active before checking health.'));
        }

        CheckWebsiteHealthJob::dispatch($website->id);

        return back()->with('success', __('Health check queued. Refresh shortly to see the result.'));
    }

    /**
     * Authorize website visibility and return its provisioning log as a plain-text download, or fail with 404 when absent.
     */
    public function downloadProvisioningLog(
        Website $website,
        PlainTextLogDownload $download,
    ): Response {
        $this->authorize('view', $website);

        $log = $website->logs()
            ->where('type', Website::PROVISIONING_LOG_TYPE)
            ->firstOrFail();

        return $download->make(
            $log->log,
            "lessbuild-website-{$website->id}-provisioning.log",
        );
    }

    /**
     * Remove the specified resource from storage
     *
     * @return RedirectResponse
     */
    public function destroy(Website $website): RedirectResponse
    {
        $this->authorize('delete', $website);

        $deleted = DB::transaction(function () use ($website): bool {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->hasActiveDeployment()) {
                return false;
            }

            return (bool) $locked->delete();
        });

        if (! $deleted) {
            return back()->with('error', __('Wait for the current deployment to finish before deleting this website.'));
        }

        return redirect()
            ->route('websites.index')
            ->with('success', __('Website deletion queued.'));
    }

    /** @return array{search: ?string, status: ?string, health: ?string, attention: ?string, provisioning: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $request->string('status')->toString();
        $health = $request->string('health')->toString();
        $healthStatuses = ['disabled', Website::HEALTH_UNKNOWN, Website::HEALTH_HEALTHY, Website::HEALTH_UNHEALTHY];

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, $this->websiteStatuses(), true) ? $status : null,
            'health' => in_array($health, $healthStatuses, true) ? $health : null,
            'attention' => $request->boolean('attention') ? '1' : null,
            'provisioning' => $request->boolean('provisioning') ? '1' : null,
        ];
    }

    /** @return list<string> */
    private function websiteStatuses(): array
    {
        return [
            Website::STATUS_QUEUED,
            Website::STATUS_PROVISIONING,
            Website::STATUS_ACTIVE,
            Website::STATUS_FAILED,
        ];
    }
}
