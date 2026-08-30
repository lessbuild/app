<?php

namespace App\Http\Controllers;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepositoriesController extends Controller
{
    /**
     * Display a listing of the resource.
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
     */
    public function show(Repository $repository): View
    {
        $this->authorize('view', $repository);

        return view('scenes.repositories.show', [
            'repository' => $repository,
            'builds' => $repository->builds()->latest()->limit(10)->get(),
            'deploymentInProgress' => $repository->builds()
                ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])
                ->exists(),
        ]);
    }

    /**
     * Display the form to create a resource
     */
    public function create(Request $request): View
    {
        $providers = $request->user()->providers()->forRepositories()->get();
        $websites = $request->user()->websites()->get();

        return view('scenes.repositories.create', [
            'providers' => $providers,
            'websites' => $websites,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => [
                'required',
                Rule::exists('providers', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('provider', Provider::TYPE_GITHUB)
                    ->whereNull('deleted_at')),
            ],
            'website_id' => [
                'required',
                Rule::exists('websites', 'id')->where('user_id', $request->user()->id),
            ],
            'name' => 'required|max:255',
            'url' => 'required|max:255',
            'description' => 'required',
        ]);

        $repository = $request->user()->repositories()->create($validated);

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Repository $repository): View
    {
        $this->authorize('update', $repository);

        $providers = $request->user()->providers()->forRepositories()->get();
        $websites = $request->user()->websites()->get();

        return view('scenes.repositories.edit', [
            'repository' => $repository,
            'providers' => $providers,
            'websites' => $websites,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return RedirectResponse
     */
    public function update(Request $request, Repository $repository)
    {
        $this->authorize('update', $repository);

        $validated = $request->validate([
            'provider_id' => [
                'required',
                Rule::exists('providers', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('provider', Provider::TYPE_GITHUB)
                    ->whereNull('deleted_at')),
            ],
            'website_id' => [
                'required',
                Rule::exists('websites', 'id')->where('user_id', $request->user()->id),
            ],
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
     * @return RedirectResponse
     */
    public function destroy(Repository $repository)
    {
        $this->authorize('delete', $repository);

        $repository->delete();

        return redirect()->route('repositories.index');
    }

    /**
     * Deploy a repo
     */
    public function deploy(Repository $repository): RedirectResponse
    {
        $this->authorize('deploy', $repository);

        $deploymentInProgress = $repository->builds()
            ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])
            ->exists();

        if ($deploymentInProgress) {
            return back()->with('info', 'A deployment is already in progress');
        }

        $repository->update(['setup_stage' => 0]);
        $build = $repository->builds()->create([
            'status' => Build::STATUS_QUEUED,
        ]);

        PublishRepositoryJob::dispatch($build);

        return back()->with('success', 'Deployment queued');
    }
}
