<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderRequest;
use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderController extends Controller
{
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
            'types' => $this->providerTypes(),
            'usages' => ['in_use', 'unused'],
            'connectionStatuses' => Provider::CONNECTION_STATUSES,
        ]);
    }

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
                        $provider->connection_checked_at?->toIso8601String(),
                        $provider->created_at?->toIso8601String(),
                        $provider->updated_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
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
            'sources' => [ProviderConnectionCheck::SOURCE_MANUAL, ProviderConnectionCheck::SOURCE_AUTOMATIC],
        ]);
    }

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

        return [
            'result' => in_array($result, ['healthy', 'failed'], true) ? $result : null,
            'source' => in_array($source, [
                ProviderConnectionCheck::SOURCE_MANUAL,
                ProviderConnectionCheck::SOURCE_AUTOMATIC,
            ], true) ? $source : null,
            'date_from' => $this->date($request->string('date_from')->toString()),
            'date_to' => $this->date($request->string('date_to')->toString()),
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
        $provider = $request->user()->providers()->create(array_merge($request->validated(), [
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
        return $request->user()->providers()
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('description', 'like', "%{$value}%");
                });
            })
            ->when($filters['type'], fn ($query, string $value) => $query
                ->where('provider', $value))
            ->when($filters['usage'] === 'in_use', fn ($query) => $query
                ->where(function ($query): void {
                    $query->whereHas('servers')->orWhereHas('repositories');
                }))
            ->when($filters['usage'] === 'unused', fn ($query) => $query
                ->whereDoesntHave('servers')
                ->whereDoesntHave('repositories'))
            ->when($filters['connection'] === Provider::CONNECTION_UNCHECKED, fn ($query) => $query
                ->whereNull('connection_status'))
            ->when($filters['connection'] && $filters['connection'] !== Provider::CONNECTION_UNCHECKED, fn ($query) => $query
                ->where('connection_status', $filters['connection']));
    }

    /** @return list<string> */
    private function providerTypes(): array
    {
        return array_values(array_unique([
            ...Provider::SERVER_TYPES,
            ...Provider::SOURCE_CONTROL_TYPES,
        ]));
    }

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
