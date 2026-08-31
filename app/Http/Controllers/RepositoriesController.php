<?php

namespace App\Http\Controllers;

use App\Http\Requests\RepositoryRequest;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                ->whereIn('status', Build::ACTIVE_STATUSES)
                ->exists(),
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

        $repository->update($request->validated());

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

        if (! $repository->isDeploymentReady()) {
            return back()->with('error', 'The website and server must be active before deployment.');
        }

        $build = DB::transaction(function () use ($repository): ?Build {
            $lockedRepository = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            $deploymentInProgress = $lockedRepository->builds()
                ->whereIn('status', Build::ACTIVE_STATUSES)
                ->exists();

            if ($deploymentInProgress) {
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
