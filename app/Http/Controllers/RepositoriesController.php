<?php

namespace App\Http\Controllers;

use App\Http\Requests\RepositoryRequest;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'deploymentInProgress' => $repository->website->hasActiveDeployment(),
            'deploymentReady' => $repository->isDeploymentReady(),
        ]);
    }

    /**
     * Display the form to create a resource
     */
    public function create(Request $request): View
    {
        $providers = $request->user()->providers()->forRepositories()->get();
        $websites = $request->user()->websites()->readyForDeployments()->get();

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
    public function store(RepositoryRequest $request)
    {
        $repository = $request->user()->repositories()->create($request->validated());

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Repository $repository): View
    {
        $this->authorize('update', $repository);

        $providers = $request->user()->providers()->forRepositories()->get();
        $websites = $request->user()->websites()->readyForDeployments()->get();

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
    public function update(RepositoryRequest $request, Repository $repository)
    {
        $this->authorize('update', $repository);

        $validated = $request->validated();
        DB::transaction(function () use ($repository, $validated): void {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $locked->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                throw ValidationException::withMessages([
                    'website_id' => __('Wait for the current website deployment to finish before editing this repository.'),
                ]);
            }

            $locked->update($validated);
        });

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

        $deleted = DB::transaction(function () use ($repository): bool {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $locked->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                return false;
            }

            return (bool) $locked->delete();
        });

        if (! $deleted) {
            return back()->with('error', __('Wait for the current website deployment to finish before deleting this repository.'));
        }

        return redirect()->route('repositories.index');
    }

    /**
     * Deploy a repo
     */
    public function deploy(Repository $repository): RedirectResponse
    {
        $this->authorize('deploy', $repository);

        if (! $repository->isDeploymentReady()) {
            return back()->with('error', 'The website and server must be active before deployment.');
        }

        $build = DB::transaction(function () use ($repository): ?Build {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $lockedRepository = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $lockedRepository->website_id !== (int) $website->id) {
                return null;
            }

            if ($website->hasActiveDeployment()) {
                return null;
            }

            $lockedRepository->update(['setup_stage' => 0]);

            return $lockedRepository->builds()->create([
                'status' => Build::STATUS_QUEUED,
                'trigger_source' => Build::TRIGGER_MANUAL,
            ]);
        });

        if (! $build) {
            return back()->with('info', 'A deployment is already in progress');
        }

        PublishRepositoryJob::dispatch($build);

        return back()->with('success', 'Deployment queued');
    }
}
