<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderRequest;
use App\Models\Provider;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        return view('scenes.providers.show', [
            'provider' => $provider,
            'repositories' => $repositories,
            'servers' => $servers,
        ]);
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

        if ($provider->provider !== $request->input('provider') && $provider->hasAttachedResources()) {
            return back()->withInput()->withErrors([
                'provider' => __('A provider type cannot be changed while resources are attached.'),
            ]);
        }

        if ($request->filled('token')) {
            $validated['token'] = $request->input('token');
        }

        $provider->update(array_merge($validated, [
            'provider' => str($request->input('provider'))->lower(),
        ]));

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

    /** @return array{search: ?string, type: ?string, usage: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $type = $request->string('type')->toString();
        $usage = $request->string('usage')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'type' => in_array($type, $this->providerTypes(), true) ? $type : null,
            'usage' => in_array($usage, ['in_use', 'unused'], true) ? $usage : null,
        ];
    }

    /** @param array{search: ?string, type: ?string, usage: ?string} $filters */
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
                ->whereDoesntHave('repositories'));
    }

    /** @return list<string> */
    private function providerTypes(): array
    {
        return array_values(array_unique([
            ...Provider::SERVER_TYPES,
            ...Provider::SOURCE_CONTROL_TYPES,
        ]));
    }
}
