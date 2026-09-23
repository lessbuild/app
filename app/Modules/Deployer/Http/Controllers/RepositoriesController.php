<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Repository\CreateRepositoryAction;
use App\Modules\Deployer\Actions\Repository\DeleteRepositoryAction;
use App\Modules\Deployer\Actions\Repository\DeployRepositoryAction;
use App\Modules\Deployer\Actions\Repository\UpdateRepositoryAction;
use App\Modules\Deployer\Exceptions\RepositoryWebhookConfigurationException;
use App\Modules\Deployer\Http\Requests\RepositoryImpactPreviewRequest;
use App\Modules\Deployer\Http\Requests\RepositoryIndexRequest;
use App\Modules\Deployer\Http\Requests\RepositoryRequest;
use App\Modules\Deployer\Http\Requests\RepositoryWebhookDeliveryRequest;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\RepositoryWebhookDelivery;
use App\Modules\Deployer\Services\DeploymentGate;
use App\Modules\Deployer\Services\DeploymentPreflight;
use App\Modules\Deployer\Services\DeploymentPreflightGuidance;
use App\Modules\Deployer\Services\RepositoryDeploymentInsightsQuery;
use App\Modules\Deployer\Services\RepositoryImpactPreviewQuery;
use App\Modules\Deployer\Services\RepositoryInventoryExporter;
use App\Modules\Deployer\Services\RepositoryInventoryQuery;
use App\Modules\Deployer\Services\RepositoryWebhookDeliveryHistoryExporter;
use App\Modules\Deployer\Services\RepositoryWebhookDeliveryHistoryQuery;
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
        private readonly RepositoryImpactPreviewQuery $impactPreview,
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
     * Show a read-only impact preview for changed paths across enabled workspace push targets.
     */
    public function impactPreview(RepositoryImpactPreviewRequest $request): View
    {
        $this->authorize('viewAny', Repository::class);
        $preview = $request->hasPreviewInput()
            ? $this->impactPreview->for($request->user(), $request->changedPaths())
            : null;

        if ($request->string('fragment')->toString() === 'repository-impact-preview') {
            return view('components.scenes.repositories.impact-preview-content', [
                'preview' => $preview,
                'changedPathsInput' => $request->changedPathsInput(),
                'pathsUnavailable' => $request->pathsUnavailable(),
                'fragment' => true,
            ]);
        }

        return view('scenes.repositories.impact-preview', [
            'preview' => $preview,
            'changedPathsInput' => $request->changedPathsInput(),
            'pathsUnavailable' => $request->pathsUnavailable(),
        ]);
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

        if ($request->string('fragment')->toString() === 'webhook-delivery') {
            $deliveryId = filter_var($request->query('delivery_id'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            abort_if($deliveryId === false, 404);

            $delivery = $repository->webhookDeliveries()
                ->with('build')
                ->whereKey($deliveryId)
                ->first();
            abort_if($delivery === null, 404);

            return view('components.scenes.repositories.webhook-delivery-content', [
                'repository' => $repository,
                'delivery' => $delivery,
            ]);
        }

        $deliveryFilters = $request->filters();
        $environment = $gate->environment($repository);
        $deploymentPreflight = $preflight->assess($repository, $environment);
        $isFirstDeployment = ! $repository->builds()->exists();
        $deploymentGuidance = $isFirstDeployment
            ? $guidance->for($repository, $environment, $request->user(), $deploymentPreflight)
            : null;
        $editDialogOpen = $request->query('dialog') === 'edit-repository';
        $websiteEditDialogOpen = $request->query('dialog') === 'edit-website';
        $providers = null;
        $websites = null;
        $websiteEditServers = null;
        if ($editDialogOpen) {
            $providers = $request->user()->workspaceProviders()
                ->forRepositories()
                ->orderBy('name')
                ->get();
            $websites = $request->user()->workspaceWebsites()
                ->readyForDeployments()
                ->orderBy('name')
                ->get();
        }
        if ($websiteEditDialogOpen) {
            $websiteEditServers = $request->user()->workspaceServers()
                ->readyForWebsites()
                ->orderBy('name')
                ->get();
        }

        return view('scenes.repositories.show', [
            'repository' => $repository,
            'editDialogOpen' => $editDialogOpen,
            'websiteEditDialogOpen' => $websiteEditDialogOpen,
            'providers' => $providers,
            'websites' => $websites,
            'websiteEditServers' => $websiteEditServers,
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
            'selectedWebhookDelivery' => $this->selectedWebhookDelivery($request, $repository),
            'deploymentInProgress' => $repository->website->hasActiveDeployment(),
            'deploymentReady' => $repository->isDeploymentReady(),
            'deploymentPreflight' => $deploymentPreflight,
            'deploymentGuidance' => $deploymentGuidance,
            'deploymentPlanBlocked' => ($deploymentGuidance['plan']['status'] ?? null) === 'failed',
            'isFirstDeployment' => $isFirstDeployment,
        ]);
    }

    private function selectedWebhookDelivery(Request $request, Repository $repository): ?RepositoryWebhookDelivery
    {
        $dialog = $request->string('dialog')->toString();

        if (! preg_match('/^webhook-delivery-(\d+)$/', $dialog, $matches)) {
            return null;
        }

        return $repository->webhookDeliveries()
            ->with('build')
            ->whereKey((int) $matches[1])
            ->first();
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

        if ($request->boolean('fragment')) {
            return view('components.scenes.repositories.edit-dialog-content', [
                'repository' => $repository,
                'providers' => $providers,
                'websites' => $websites,
                'cancelUrl' => $this->safeReturnUrl($request, route('repositories.show', $repository)),
            ]);
        }

        return view('scenes.repositories.edit', [
            'repository' => $repository,
            'providers' => $providers,
            'websites' => $websites,
        ]);
    }

    /**
     * Keep dialog cancellation on the current same-origin page.
     */
    private function safeReturnUrl(Request $request, string $fallback): string
    {
        $candidate = $request->string('return_to')->toString();

        if ($candidate === '') {
            return $fallback;
        }

        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return $fallback;
        }

        if (isset($parts['scheme']) && $parts['scheme'] !== $request->getScheme()) {
            return $fallback;
        }

        return $candidate;
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
