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
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
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
     *
     * @param  \App\Models\Provider  $provider
     * @return \Illuminate\Contracts\View\View
     */
    public function show(Provider $provider): View
    {
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
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function create(): View
    {
        return view('scenes.providers.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\ProviderRequest  $request
     * @return \Illuminate\Http\RedirectResponse
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
     *
     * @param  \App\Models\Provider  $provider
     * @return \Illuminate\Contracts\View\View
     */
    public function edit(Provider $provider): View
    {
        return view('scenes.providers.edit', [
            'provider' => $provider,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\ProviderRequest  $request
     * @param  \App\Models\Provider  $provider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ProviderRequest $request, Provider $provider): RedirectResponse
    {
        $provider = $provider->update(array_merge($request->validated(), [
            'provider' => str($request->input('provider'))->lower(),
        ]));

        return redirect()->route('providers.show', $provider);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Provider  $provider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Provider $provider): RedirectResponse
    {
        $provider->delete();

        return redirect()->route('providers.index');
    }
}
