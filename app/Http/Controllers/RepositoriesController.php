<?php

namespace App\Http\Controllers;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RepositoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
     */
    public function index(Request $request): View
    {
        $repositories = $request->user()->repositories()->get();

        return view('scenes.repositories.index', [
            'repositories' => $repositories,
        ]);
    }

    /**
     * Show the resource
     *
     * @param  \App\Models\Repository  $repository
     * @return \Illuminate\Contracts\View\View
     */
    public function show(Repository $repository): View
    {
        return view('scenes.repositories.show', [
            'repository' => $repository,
        ]);
    }

    /**
     * Display the form to create a resource
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
     */
    public function create(Request $request): View
    {
        $providers = $request->user()->providers()->whereIn('provider', ['github'])->get();
        $websites = $request->user()->websites()->get();

        return view('scenes.repositories.create', [
            'providers' => $providers,
            'websites' => $websites,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => 'required|exists:providers,id',
            'website_id' => 'required|exists:websites,id',
            'name' => 'required|max:255',
            'url' => 'required|max:255',
            'description' => 'required',
        ]);

        $repository = $request->user()->repositories()->create($validated);

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Repository  $repository
     * @return \Illuminate\Contracts\View\View
     */
    public function edit(Request $request, Repository $repository): View
    {
        $providers = $request->user()->providers()->get();

        return view('scenes.repositories.edit', [
            'repository' => $repository,
            'providers' => $providers,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Repository  $repository
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Repository $repository)
    {
        $validated = $request->validate([
            'provider_id' => 'required|exists:providers,id',
            'name' => 'required|max:255',
            'url' => 'required|max:255',
            'description' => 'required',
        ]);

        $repository->update($validated);

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * Delete the specified resource from storage.
     *
     * @param  \App\Models\Repository  $repository
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Repository $repository)
    {
        $repository->delete();

        return redirect()->route('repositories.index');
    }

    /**
     * Deploy a repo
     *
     * @param \App\Models\Repository $repository
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deploy(Repository $repository): RedirectResponse
    {
        $repository->update(['setup_stage' => 0]);

        PublishRepositoryJob::dispatch($repository);

        return back()->with('success', 'Repository deployed');
    }
}
