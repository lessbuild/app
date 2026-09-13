<?php

namespace App\Http\Controllers;

use App\Actions\Repository\CreateRepositoryAction;
use App\Actions\Repository\DeleteRepositoryAction;
use App\Actions\Repository\DeployRepositoryAction;
use App\Actions\Repository\UpdateRepositoryAction;
use App\Exceptions\RepositoryWebhookConfigurationException;
use App\Http\Requests\RepositoryIndexRequest;
use App\Http\Requests\RepositoryRequest;
use App\Http\Requests\RepositoryWebhookDeliveryRequest;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Services\DeploymentGate;
use App\Services\DeploymentPreflight;
use App\Services\DeploymentPreflightGuidance;
use App\Services\RepositoryDeploymentInsightsQuery;
use App\Services\RepositoryInventoryExporter;
use App\Services\RepositoryInventoryQuery;
use App\Services\RepositoryWebhookDeliveryHistoryExporter;
use App\Services\RepositoryWebhookDeliveryHistoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepositoriesController extends Controller
{
    public function __construct(
        private readonly RepositoryInventoryExporter $repositoryInventoryExporter,
        private readonly RepositoryInventoryQuery $repositoryInventory,
        private readonly RepositoryWebhookDeliveryHistoryExporter $webhookDeliveryHistoryExporter,
        private readonly RepositoryWebhookDeliveryHistoryQuery $webhookDeliveryHistory,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(RepositoryIndexRequest $request): View
    {
        $filters = $request->filters();
        $repositories = $this->repositoryInventory->for($request->user(), $filters)
            ->with(['provider', 'website.server', 'latestBuild'])
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.repositories.index', [
            'repositories' => $repositories,
            'filters' => $filters,
            'metrics' => $this->repositoryInventory->metrics($request->user(), $filters),
            'providers' => $request->user()->workspaceProviders()
                ->forRepositories()
                ->orderBy('name')
                ->get(['id', 'name']),
            'websites' => $request->user()->workspaceWebsites()
                ->orderBy('name')
                ->get(['id', 'name']),
            'statuses' => $request->statuses(),
        ]);
    }

    /**
     * Stream filtered workspace repositories with provider, placement, latest-deployment, and webhook metadata as private CSV.
     */
    public function export(RepositoryIndexRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->repositoryInventoryExporter->stream($request->user(), $filters);
    }

    /**
     * Show the resource
     */
    public function show(
        RepositoryWebhookDeliveryRequest $request,
        Repository $repository,
        DeploymentGate $gate,
        DeploymentPreflight $preflight,
        DeploymentPreflightGuidance $guidance,
        RepositoryDeploymentInsightsQuery $deploymentInsights,
    ): View {
        $this->authorize('view', $repository);
        $deliveryFilters = $request->filters();
        $environment = $gate->environment($repository);
        $deploymentPreflight = $preflight->assess($repository, $environment);
        $isFirstDeployment = ! $repository->builds()->exists();
        $deploymentGuidance = $isFirstDeployment
            ? $guidance->for($repository, $environment, $request->user(), $deploymentPreflight)
            : null;

        return view('scenes.repositories.show', [
            'repository' => $repository,
            'builds' => $repository->builds()->latest()->limit(10)->get(),
            'deploymentMetrics' => $deploymentInsights->metrics($repository),
            'webhookDeliveries' => $this->webhookDeliveryHistory->for($repository, $deliveryFilters)
                ->with('build')
                ->latest('id')
                ->paginate(10, pageName: 'webhook_page')
                ->appends(array_filter($deliveryFilters, fn ($value) => $value !== null)),
            'deliveryFilters' => $deliveryFilters,
            'deliveryMetrics' => $this->webhookDeliveryHistory->metrics($repository, $deliveryFilters),
            'deliveryStatuses' => RepositoryWebhookDelivery::STATUSES,
            'deploymentInProgress' => $repository->website->hasActiveDeployment(),
            'deploymentReady' => $repository->isDeploymentReady(),
            'deploymentPreflight' => $deploymentPreflight,
            'deploymentGuidance' => $deploymentGuidance,
            'deploymentPlanBlocked' => ($deploymentGuidance['plan']['status'] ?? null) === 'failed',
            'isFirstDeployment' => $isFirstDeployment,
        ]);
    }

    /**
     * Authorize repository visibility and stream its filtered webhook delivery and build outcomes as private CSV.
     */
    public function exportWebhookDeliveries(RepositoryWebhookDeliveryRequest $request, Repository $repository): StreamedResponse
    {
        $this->authorize('view', $repository);
        $deliveryFilters = $request->filters();

        return $this->webhookDeliveryHistoryExporter->stream($repository, $deliveryFilters);
    }

    /**
     * Display the form to create a resource
     */
    public function create(Request $request): View
    {
        $providers = $request->user()->workspaceProviders()->forRepositories()->get();
        $websites = $request->user()->workspaceWebsites()->readyForDeployments()->get();

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
    public function store(RepositoryRequest $request, CreateRepositoryAction $create): RedirectResponse
    {
        $attributes = $request->validated();
        try {
            $repository = $create->handle($request->user(), $attributes);
        } catch (RepositoryWebhookConfigurationException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Repository $repository): View
    {
        $this->authorize('update', $repository);

        $providers = $request->user()->workspaceProviders()->forRepositories()->get();
        $websites = $request->user()->workspaceWebsites()->readyForDeployments()->get();

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
    public function update(RepositoryRequest $request, Repository $repository, UpdateRepositoryAction $update): RedirectResponse
    {
        $this->authorize('update', $repository);

        $validated = $request->validated();
        try {
            $updated = $update->handle($repository, $request->user(), $validated);
        } catch (RepositoryWebhookConfigurationException $exception) {
            abort(503, $exception->getMessage());
        }
        if (! $updated) {
            throw ValidationException::withMessages([
                'website_id' => __('Wait for the current website deployment to finish before editing this repository.'),
            ]);
        }

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * Delete the specified resource from storage.
     *
     * @return RedirectResponse
     */
    public function destroy(Repository $repository, DeleteRepositoryAction $delete): RedirectResponse
    {
        $this->authorize('delete', $repository);

        $deleted = $delete->handle($repository);

        if (! $deleted) {
            return back()->with('error', __('Wait for the current website deployment to finish before deleting this repository.'));
        }

        return redirect()->route('repositories.index');
    }

    /**
     * Deploy a repo
     */
    public function deploy(Request $request, Repository $repository, DeployRepositoryAction $deploy, DeploymentGate $gate): RedirectResponse
    {
        $this->authorize('deploy', $repository);

        if (! $repository->isDeploymentReady()) {
            return back()->with('error', 'The website and server must be active before deployment.');
        }
        if ($reason = $gate->blockReason($repository)) {
            return back()->with('error', $reason);
        }

        $build = $deploy->handle($repository, $request->user());

        if (! $build) {
            return back()->with('info', 'A deployment is already in progress');
        }

        return redirect()->route('builds.show', $build)->with('success', $build->status === Build::STATUS_AWAITING_APPROVAL
            ? 'Deployment submitted for approval'
            : 'Deployment queued');
    }
}
