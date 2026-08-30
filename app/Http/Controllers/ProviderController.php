<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderRequest;
use App\Models\Provider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $providers = $request->user()
            ->providers()
            ->latest()
            ->paginate();

        return view('scenes.providers.index', [
            'providers' => $providers,
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
            ->paginate();

        $servers = $provider->servers()
            ->latest()
            ->paginate();

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
}
