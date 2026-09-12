<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderRequest;
use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Services\Entitlements;
use App\Services\ProviderConnectionHistoryExporter;
use App\Services\ProviderConnectionHistoryQuery;
use App\Services\ProviderInventoryExporter;
use App\Services\ProviderInventoryQuery;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderController extends Controller
{
    /**
     * Use workspace entitlements to guard provider features that depend on a paid plan.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ProviderConnectionHistoryQuery $connectionHistory,
        private readonly ProviderConnectionHistoryExporter $connectionHistoryExporter,
        private readonly ProviderInventoryExporter $providerInventoryExporter,
        private readonly ProviderInventoryQuery $providerInventory,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $providers = $this->providerInventory->for($request->user(), $filters)
            ->withCount(['servers', 'repositories'])
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.providers.index', [
            'providers' => $providers,
            'filters' => $filters,
            'metrics' => $this->providerInventory->metrics($request->user(), $filters),
            'types' => $this->providerTypes(),
            'usages' => ['in_use', 'unused'],
            'connectionStatuses' => Provider::CONNECTION_STATUSES,
        ]);
    }

    /**
     * Stream filtered workspace providers, their resource associations, and monitoring configuration as private CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);

        return $this->providerInventoryExporter->stream($request->user(), $filters);
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
            'connectionMetrics' => $this->connectionHistory->metrics($retainedConnectionChecks),
        ]);
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
            'connectionChecks' => $this->connectionHistory->for($provider, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->connectionHistory->filteredMetrics($provider, $filters),
            'sources' => [ProviderConnectionCheck::SOURCE_MANUAL, ProviderConnectionCheck::SOURCE_AUTOMATIC],
        ]);
    }

    /**
     * Authorize provider visibility and stream bounded, filtered connection-check history as private CSV.
     */
    public function exportConnectionChecks(Request $request, Provider $provider): StreamedResponse
    {
        $this->authorize('view', $provider);
        $filters = $this->connectionCheckFilters($request);

        return $this->connectionHistoryExporter->stream($provider, $filters);
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

        return redirect()->route('providers.show', $provider)->with('success', __('Provider created successfully.'));
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

        return redirect()->route('providers.show', $provider)->with('success', __('Provider updated successfully.'));
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

    /** @return list<string> */
    private function providerTypes(): array
    {
        return array_values(array_unique([
            ...Provider::SERVER_TYPES,
            ...Provider::SOURCE_CONTROL_TYPES,
            ...Provider::DNS_TYPES,
        ]));
    }
}
