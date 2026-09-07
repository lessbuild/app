<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderRequest;
use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Services\Entitlements;
use App\Support\CsvCell;
use App\Support\DateRange;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderController extends Controller
{
    /**
     * Use workspace entitlements to guard provider features that depend on a paid plan.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $providers = $this->filteredProviders($request, $filters)
            ->withCount(['servers', 'repositories'])
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.providers.index', [
            'providers' => $providers,
            'filters' => $filters,
            'metrics' => $this->indexMetrics($request, $filters),
            'types' => $this->providerTypes(),
            'usages' => ['in_use', 'unused'],
            'connectionStatuses' => Provider::CONNECTION_STATUSES,
        ]);
    }

    /**
     * @param  array{search: ?string, type: ?string, usage: ?string, connection: ?string}  $filters
     * @return array{total: int, in_use: int, unused: int, healthy: int, failed: int, unchecked: int}
     */
    private function indexMetrics(Request $request, array $filters): array
    {
        return [
            'total' => $this->filteredProviders($request, $filters)->count(),
            'in_use' => $this->filteredProviders($request, $filters)->inUse()->count(),
            'unused' => $this->filteredProviders($request, $filters)->unused()->count(),
            'healthy' => $this->filteredProviders($request, $filters)
                ->connectionState(Provider::CONNECTION_HEALTHY)
                ->count(),
            'failed' => $this->filteredProviders($request, $filters)
                ->connectionState(Provider::CONNECTION_FAILED)
                ->count(),
            'unchecked' => $this->filteredProviders($request, $filters)
                ->connectionState(Provider::CONNECTION_UNCHECKED)
                ->count(),
        ];
    }

    /**
     * Stream filtered workspace providers, their resource associations, and monitoring configuration as private CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);
        $filename = 'lessbuild-providers-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Provider ID',
                'Name',
                'Type',
                'Description',
                'Servers',
                'Server count',
                'Repositories',
                'Repository count',
                'Connection status',
                'Automatic monitoring',
                'Automatic interval minutes',
                'Failure threshold',
                'Consecutive failures',
                'Connection checked at',
                'Created at',
                'Updated at',
            ], ',', '"', '');

            $this->filteredProviders($request, $filters)
                ->with([
                    'servers' => fn ($query) => $query
                        ->select(['id', 'provider_id', 'name', 'display_name'])
                        ->orderBy('name'),
                    'repositories' => fn ($query) => $query
                        ->select(['id', 'provider_id', 'name'])
                        ->orderBy('name'),
                ])
                ->withCount(['servers', 'repositories'])
                ->latest('providers.id')
                ->lazy(250)
                ->each(function (Provider $provider) use ($output): void {
                    fputcsv($output, [
                        $provider->id,
                        $this->csvCell($provider->name),
                        $this->csvCell($provider->provider),
                        $this->csvCell($provider->description),
                        $this->csvCell($provider->servers->map->label->implode('; ')),
                        $provider->servers_count,
                        $this->csvCell($provider->repositories->pluck('name')->implode('; ')),
                        $provider->repositories_count,
                        $provider->connectionHealth(),
                        $provider->connection_monitoring_enabled ? 'enabled' : 'paused',
                        $provider->connection_check_interval_minutes,
                        $provider->connection_failure_threshold,
                        $provider->connection_failure_count,
                        $provider->connection_checked_at?->toIso8601String(),
                        $provider->created_at?->toIso8601String(),
                        $provider->updated_at?->toIso8601String(),
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
     * Show the resource
     */
    public function show(Provider $provider): View
    {
        $this->authorize('view', $provider);

        $repositories = $provider->repositories()
            ->latest()
            ->paginate(pageName: 'repositories_page');

        $servers = $provider->servers()
            ->latest()
            ->paginate(pageName: 'servers_page');
        $retainedConnectionChecks = $provider->connectionChecks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(ProviderConnectionCheck::MAX_PER_PROVIDER)
            ->get();

        return view('scenes.providers.show', [
            'provider' => $provider,
            'repositories' => $repositories,
            'servers' => $servers,
            'connectionChecks' => $retainedConnectionChecks->take(20),
            'connectionMetrics' => $this->connectionMetrics($retainedConnectionChecks),
        ]);
    }

    /**
     * @param  Collection<int, ProviderConnectionCheck>  $checks
     * @return array{total: int, successful: int, success_rate: ?int, median_successful_duration_ms: ?int, failure_streak: int}
     */
    private function connectionMetrics(Collection $checks): array
    {
        $total = $checks->count();
        $successful = $checks->where('successful', true)->count();
        $durations = $checks
            ->where('successful', true)
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
            'median_successful_duration_ms' => $medianDuration,
            'failure_streak' => $failureStreak,
        ];
    }

    /**
     * Authorize provider visibility and render filtered, paginated connection history with matching metrics.
     */
    public function connectionChecks(Request $request, Provider $provider): View
    {
        $this->authorize('view', $provider);
        $filters = $this->connectionCheckFilters($request);

        return view('scenes.providers.connection-checks', [
            'provider' => $provider,
            'connectionChecks' => $this->filteredConnectionChecks($provider, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->connectionHistoryMetrics($provider, $filters),
            'sources' => [ProviderConnectionCheck::SOURCE_MANUAL, ProviderConnectionCheck::SOURCE_AUTOMATIC],
        ]);
    }

    /**
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, healthy: int, failed: int, success_rate: ?int, median_successful_duration_ms: ?int, latest_at: CarbonInterface|null}
     */
    private function connectionHistoryMetrics(Provider $provider, array $filters): array
    {
        $checks = $this->filteredConnectionChecks($provider, $filters)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(ProviderConnectionCheck::MAX_PER_PROVIDER)
            ->get(['id', 'successful', 'duration_ms', 'checked_at']);
        $summary = $this->connectionMetrics($checks);

        return [
            'total' => $summary['total'],
            'healthy' => $summary['successful'],
            'failed' => $summary['total'] - $summary['successful'],
            'success_rate' => $summary['success_rate'],
            'median_successful_duration_ms' => $summary['median_successful_duration_ms'],
            'latest_at' => $checks->first()?->checked_at,
        ];
    }

    /**
     * Authorize provider visibility and stream bounded, filtered connection-check history as private CSV.
     */
    public function exportConnectionChecks(Request $request, Provider $provider): StreamedResponse
    {
        $this->authorize('view', $provider);
        $filters = $this->connectionCheckFilters($request);
        $filename = "lessbuild-provider-{$provider->id}-connection-checks-".now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($provider, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Check ID',
                'Result',
                'Source',
                'Provider type',
                'HTTP status',
                'Duration ms',
                'Endpoint',
                'Error',
                'Checked at',
            ], ',', '"', '');

            $this->filteredConnectionChecks($provider, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(ProviderConnectionCheck::MAX_PER_PROVIDER)
                ->get()
                ->each(function (ProviderConnectionCheck $check) use ($output): void {
                    fputcsv($output, [
                        $check->id,
                        $check->successful ? 'healthy' : 'failed',
                        $check->source,
                        $this->csvCell($check->provider_type),
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
    private function connectionCheckFilters(Request $request): array
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
                ProviderConnectionCheck::SOURCE_MANUAL,
                ProviderConnectionCheck::SOURCE_AUTOMATIC,
            ], true) ? $source : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param array{result: ?string, source: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredConnectionChecks(Provider $provider, array $filters): HasMany
    {
        return $provider->connectionChecks()
            ->when($filters['result'] !== null, fn ($query) => $query
                ->where('successful', $filters['result'] === 'healthy'))
            ->when($filters['source'], fn ($query, string $source) => $query
                ->where('source', $source))
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('checked_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('checked_at', '<=', $date));
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
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('scenes.providers.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProviderRequest $request): RedirectResponse
    {
        if ($request->boolean('connection_monitoring_enabled')) {
            $this->entitlements->enforce($request->user()->currentOrganization, 'monitoring');
        }

        $provider = $request->user()->workspaceProviders()->create(array_merge($request->validated(), [
            'provider' => str($request->input('provider'))->lower(),
        ]));

        return redirect()->route('providers.show', $provider);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Provider $provider): View
    {
        $this->authorize('update', $provider);

        return view('scenes.providers.edit', [
            'provider' => $provider,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProviderRequest $request, Provider $provider): RedirectResponse
    {
        $this->authorize('update', $provider);
        if ($request->boolean('connection_monitoring_enabled')) {
            $this->entitlements->enforce($request->user()->currentOrganization, 'monitoring');
        }

        $validated = $request->safe()->except('token');
        $providerType = str($request->input('provider'))->lower()->toString();
        $credentialChanged = $request->filled('token') || $provider->provider !== $providerType;

        if ($provider->provider !== $request->input('provider') && $provider->hasAttachedResources()) {
            return back()->withInput()->withErrors([
                'provider' => __('A provider type cannot be changed while resources are attached.'),
            ]);
        }

        if ($request->filled('token')) {
            $validated['token'] = $request->input('token');
        }

        $provider->update(array_merge($validated, [
            'provider' => $providerType,
        ]));

        if ($credentialChanged) {
            $provider->resetConnectionHealth();
        }

        return redirect()->route('providers.show', $provider);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Provider $provider): RedirectResponse
    {
        $this->authorize('delete', $provider);

        if ($provider->hasAttachedResources()) {
            return back()->withErrors([
                'provider' => __('Detach or delete this provider’s servers and repositories first.'),
            ]);
        }

        $provider->delete();

        return redirect()->route('providers.index');
    }

    /** @return array{search: ?string, type: ?string, usage: ?string, connection: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $type = $request->string('type')->toString();
        $usage = $request->string('usage')->toString();
        $connection = $request->string('connection')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'type' => in_array($type, $this->providerTypes(), true) ? $type : null,
            'usage' => in_array($usage, ['in_use', 'unused'], true) ? $usage : null,
            'connection' => in_array($connection, Provider::CONNECTION_STATUSES, true) ? $connection : null,
        ];
    }

    /** @param array{search: ?string, type: ?string, usage: ?string, connection: ?string} $filters */
    private function filteredProviders(Request $request, array $filters): HasMany
    {
        return $request->user()->workspaceProviders()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['type'], fn ($query, string $value) => $query
                ->where('provider', $value))
            ->when($filters['usage'] === 'in_use', fn ($query) => $query->inUse())
            ->when($filters['usage'] === 'unused', fn ($query) => $query->unused())
            ->when($filters['connection'], fn ($query, string $status) => $query->connectionState($status));
    }

    /** @return list<string> */
    private function providerTypes(): array
    {
        return array_values(array_unique([
            ...Provider::SERVER_TYPES,
            ...Provider::SOURCE_CONTROL_TYPES,
            ...Provider::DNS_TYPES,
        ]));
    }

    /**
     * Preserve null values and escape text that could be interpreted as a spreadsheet formula.
     */
    private function csvCell(?string $value): ?string
    {
        return CsvCell::escape($value);
    }
}
