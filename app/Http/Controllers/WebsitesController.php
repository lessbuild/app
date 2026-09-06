<?php

namespace App\Http\Controllers;

use App\Actions\Web\RetryWebsiteProvisioningAction;
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
use App\Support\CsvCell;
use App\Support\DateRange;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * List all created websites for the user
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $websites = $this->filteredWebsites($request, $filters)
            ->with('server')
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.websites.index', [
            'websites' => $websites,
            'filters' => $filters,
            'metrics' => $this->indexMetrics($request, $filters),
            'statuses' => $this->websiteStatuses(),
            'healthStatuses' => ['disabled', Website::HEALTH_UNKNOWN, Website::HEALTH_HEALTHY, Website::HEALTH_UNHEALTHY],
        ]);
    }

    /**
     * @param  array{search: ?string, status: ?string, health: ?string, attention: ?string, provisioning: ?string}  $filters
     * @return array{total: int, active: int, provisioning: int, failed: int, unhealthy: int, attention: int}
     */
    private function indexMetrics(Request $request, array $filters): array
    {
        return [
            'total' => $this->filteredWebsites($request, $filters)->count(),
            'active' => $this->filteredWebsites($request, $filters)
                ->where('provisioning_status', Website::STATUS_ACTIVE)
                ->count(),
            'provisioning' => $this->filteredWebsites($request, $filters)
                ->whereIn('provisioning_status', Website::ACTIVE_PROVISIONING_STATUSES)
                ->count(),
            'failed' => $this->filteredWebsites($request, $filters)
                ->where('provisioning_status', Website::STATUS_FAILED)
                ->count(),
            'unhealthy' => $this->filteredWebsites($request, $filters)
                ->where('health_check_enabled', true)
                ->where('health_status', Website::HEALTH_UNHEALTHY)
                ->count(),
            'attention' => $this->filteredWebsites($request, $filters)->needsAttention()->count(),
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);
        $filename = 'lessbuild-websites-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Website ID',
                'Name',
                'Domain',
                'Description',
                'Server',
                'Provisioning status',
                'Health check',
                'Automatic monitoring',
                'Automatic check interval minutes',
                'Outage confirmation failures',
                'Health status',
                'Health failure count',
                'Last health check at',
                'Release retention',
                'Repository count',
                'Provisioned at',
                'Created at',
            ], ',', '"', '');

            $this->filteredWebsites($request, $filters)
                ->with('server')
                ->withCount('repositories')
                ->latest('websites.id')
                ->lazy(250)
                ->each(function (Website $website) use ($output): void {
                    fputcsv($output, [
                        $website->id,
                        $this->csvCell($website->name),
                        $this->csvCell($website->url),
                        $this->csvCell($website->description),
                        $this->csvCell($website->server?->label),
                        $this->csvCell($website->provisioning_status),
                        $website->health_check_enabled ? 'enabled' : 'disabled',
                        $website->health_check_enabled
                            ? ($website->health_monitoring_enabled ? 'enabled' : 'paused')
                            : 'disabled',
                        $website->health_check_interval_minutes,
                        $website->health_failure_threshold,
                        $this->csvCell($website->health_check_enabled ? $website->health_status : 'disabled'),
                        $website->health_failure_count,
                        $website->health_last_checked_at?->toIso8601String(),
                        $website->release_retention,
                        $website->repositories_count,
                        $website->provisioned_at?->toIso8601String(),
                        $website->created_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Show the specified websites
     */
    public function show(Website $website): View
    {
        $this->authorize('view', $website);

        $repositories = $website->repositories()->with('latestBuild')->latest()->paginate();
        $retainedHealthChecks = $website->healthChecks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
            ->get();

        return view('scenes.websites.show', [
            'website' => $website,
            'repositories' => $repositories,
            'healthChecks' => $retainedHealthChecks->take(20),
            'healthMetrics' => $this->healthMetrics($retainedHealthChecks),
            'runtimeLogs' => $website->runtimeLogs()->get()->keyBy('type'),
        ]);
    }

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
     * @param  Collection<int, WebsiteHealthCheck>  $checks
     * @return array{total: int, successful: int, success_rate: ?int, median_healthy_duration_ms: ?int, failure_streak: int}
     */
    private function healthMetrics(Collection $checks): array
    {
        $total = $checks->count();
        $successful = $checks->where('successful', true)->count();
        $durations = $checks
            ->filter(fn (WebsiteHealthCheck $check): bool => $check->successful && $check->duration_ms !== null)
            ->pluck('duration_ms')
            ->sort()
            ->values();
        $durationCount = $durations->count();
        $middle = intdiv($durationCount, 2);
        $medianDuration = match (true) {
            $durationCount === 0 => null,
            $durationCount % 2 === 1 => $durations[$middle],
            default => (int) round(($durations[$middle - 1] + $durations[$middle]) / 2),
        };
        $failureStreak = 0;
        foreach ($checks as $check) {
            if ($check->successful) {
                break;
            }

            $failureStreak++;
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'success_rate' => $total > 0 ? (int) round(($successful / $total) * 100) : null,
            'median_healthy_duration_ms' => $medianDuration,
            'failure_streak' => $failureStreak,
        ];
    }

    public function healthChecks(Request $request, Website $website): View
    {
        $this->authorize('view', $website);
        $filters = $this->healthCheckFilters($request);

        return view('scenes.websites.health-checks', [
            'website' => $website,
            'healthChecks' => $this->filteredHealthChecks($website, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->healthHistoryMetrics($website, $filters),
            'sources' => [WebsiteHealthCheck::SOURCE_MANUAL, WebsiteHealthCheck::SOURCE_AUTOMATIC],
        ]);
    }

    /**
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, healthy: int, failed: int, success_rate: ?int, median_healthy_duration_ms: ?int, latest_at: CarbonInterface|null}
     */
    private function healthHistoryMetrics(Website $website, array $filters): array
    {
        $checks = $this->filteredHealthChecks($website, $filters)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
            ->get(['id', 'successful', 'duration_ms', 'checked_at']);
        $summary = $this->healthMetrics($checks);

        return [
            'total' => $summary['total'],
            'healthy' => $summary['successful'],
            'failed' => $summary['total'] - $summary['successful'],
            'success_rate' => $summary['success_rate'],
            'median_healthy_duration_ms' => $summary['median_healthy_duration_ms'],
            'latest_at' => $checks->first()?->checked_at,
        ];
    }

    public function exportHealthChecks(Request $request, Website $website): StreamedResponse
    {
        $this->authorize('view', $website);
        $filters = $this->healthCheckFilters($request);
        $filename = "lessbuild-website-{$website->id}-health-checks-".now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($website, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Check ID',
                'Result',
                'Source',
                'HTTP status',
                'Duration ms',
                'Endpoint',
                'Error',
                'Checked at',
            ], ',', '"', '');

            $this->filteredHealthChecks($website, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
                ->get()
                ->each(function (WebsiteHealthCheck $check) use ($output): void {
                    fputcsv($output, [
                        $check->id,
                        $check->successful ? 'healthy' : 'failed',
                        $check->source,
                        $check->http_status,
                        $check->duration_ms,
                        $this->csvCell($check->endpoint),
                        $this->csvCell($check->error),
                        $check->checked_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

    /** @param array{result: ?string, source: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredHealthChecks(Website $website, array $filters): HasMany
    {
        return $website->healthChecks()
            ->when($filters['result'] !== null, fn ($query) => $query
                ->where('successful', $filters['result'] === 'healthy'))
            ->when($filters['source'], fn ($query, string $source) => $query
                ->where('source', $source))
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('checked_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('checked_at', '<=', $date));
    }

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
        DB::transaction(function () use ($validated, $website): void {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->hasActiveDeployment()) {
                throw ValidationException::withMessages([
                    'server_id' => __('Wait for the current website deployment to finish before editing this website.'),
                ]);
            }
            if (in_array($locked->provisioning_status, [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING], true)) {
                throw ValidationException::withMessages([
                    'server_id' => __('Wait for the current website provisioning operation to finish.'),
                ]);
            }

            $moving = (int) $validated['server_id'] !== (int) $locked->server_id;
            $healthSettingsChanged = ! $validated['health_check_enabled']
                || ! $locked->health_check_enabled
                || $validated['health_check_path'] !== $locked->health_check_path
                || $validated['url'] !== $locked->url
                || $moving;
            if ($healthSettingsChanged) {
                $validated = array_merge($validated, [
                    'health_status' => Website::HEALTH_UNKNOWN,
                    'health_failure_count' => 0,
                    'health_last_checked_at' => null,
                    'health_last_error' => null,
                ]);
            }
            if ($moving && $locked->previous_server_id) {
                throw ValidationException::withMessages([
                    'server_id' => __('Finish cleaning up the previous server before moving this website again.'),
                ]);
            }

            $requiresProvisioning = $locked->provisioning_status === Website::STATUS_FAILED
                || $moving
                || $validated['url'] !== $locked->url
                || $validated['environment'] !== $locked->environment;
            if (! $requiresProvisioning) {
                $locked->update($validated);

                return;
            }

            $locked->update(array_merge($validated, [
                'previous_server_id' => $moving ? $locked->server_id : $locked->previous_server_id,
                'placement_cleanup_error' => $moving ? null : $locked->placement_cleanup_error,
                'provisioning_token' => (string) Str::uuid(),
                'setup_stage' => 0,
                'provisioning_status' => Website::STATUS_QUEUED,
                'provisioning_error' => null,
                'provisioned_at' => null,
            ]));
            $locked->logs()->where('type', Website::PROVISIONING_LOG_TYPE)->delete();

            AddWebsiteJob::dispatch($locked)->afterCommit();
        });

        return redirect()->route('websites.show', $website);
    }

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

    /** @param array{search: ?string, status: ?string, health: ?string, attention: ?string, provisioning: ?string} $filters */
    private function filteredWebsites(Request $request, array $filters): HasMany
    {
        return $request->user()->workspaceWebsites()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('provisioning_status', $value))
            ->when($filters['health'], function ($query, string $value): void {
                if ($value === 'disabled') {
                    $query->where('health_check_enabled', false);

                    return;
                }

                $query
                    ->where('health_check_enabled', true)
                    ->where('health_status', $value);
            })
            ->when($filters['attention'], fn ($query) => $query->needsAttention())
            ->when($filters['provisioning'], fn ($query) => $query
                ->whereIn('provisioning_status', Website::ACTIVE_PROVISIONING_STATUSES));
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

    private function csvCell(string|int|null $value): ?string
    {
        return CsvCell::escape($value);
    }
}
