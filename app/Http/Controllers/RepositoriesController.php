<?php

namespace App\Http\Controllers;

use App\Actions\Repository\DeleteRepositoryAction;
use App\Actions\Repository\DeployRepositoryAction;
use App\Actions\Repository\UpdateRepositoryAction;
use App\Http\Requests\RepositoryRequest;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Services\DeploymentGate;
use App\Services\DeploymentPreflight;
use App\Services\RepositoryDeploymentInsightsQuery;
use App\Services\RepositoryInventoryExporter;
use App\Services\RepositoryInventoryQuery;
use App\Services\RepositoryWebhookDeliveryHistoryExporter;
use App\Services\RepositoryWebhookDeliveryHistoryQuery;
use App\Support\DateRange;
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
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
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
            'statuses' => $this->repositoryStatuses(),
        ]);
    }

    /**
     * Stream filtered workspace repositories with provider, placement, latest-deployment, and webhook metadata as private CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);

        return $this->repositoryInventoryExporter->stream($request->user(), $filters);
    }

    /** @return array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $request->string('status')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'provider_id' => $this->positiveInteger($request->query('provider_id')),
            'website_id' => $this->positiveInteger($request->query('website_id')),
            'status' => in_array($status, $this->repositoryStatuses(), true) ? $status : null,
        ];
    }

    /**
     * Normalize untrusted identifier input into a positive integer, returning null for invalid or non-positive values.
     */
    private function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $integer ?: null;
    }

    /** @return list<string> */
    private function repositoryStatuses(): array
    {
        return [
            'none',
            ...array_values(array_unique(array_merge(Build::ACTIVE_STATUSES, Build::TERMINAL_STATUSES))),
        ];
    }

    /**
     * Show the resource
     */
    public function show(
        Request $request,
        Repository $repository,
        DeploymentGate $gate,
        DeploymentPreflight $preflight,
        RepositoryDeploymentInsightsQuery $deploymentInsights,
    ): View {
        $this->authorize('view', $repository);
        $deliveryFilters = $this->deliveryFilters($request);

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
            'deploymentPreflight' => $preflight->assess($repository, $gate->environment($repository)),
            'isFirstDeployment' => ! $repository->builds()->exists(),
        ]);
    }

    /**
     * Authorize repository visibility and stream its filtered webhook delivery and build outcomes as private CSV.
     */
    public function exportWebhookDeliveries(Request $request, Repository $repository): StreamedResponse
    {
        $this->authorize('view', $repository);
        $deliveryFilters = $this->deliveryFilters($request);

        return $this->webhookDeliveryHistoryExporter->stream($repository, $deliveryFilters);
    }

    /** @return array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string} */
    private function deliveryFilters(Request $request): array
    {
        $status = $request->string('delivery_status')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('delivery_date_from')->toString(),
            $request->string('delivery_date_to')->toString(),
        );

        return [
            'delivery_status' => in_array($status, RepositoryWebhookDelivery::STATUSES, true) ? $status : null,
            'delivery_date_from' => $dateFrom,
            'delivery_date_to' => $dateTo,
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
    public function store(RepositoryRequest $request): RedirectResponse
    {
        $attributes = $request->validated();
        $provider = $request->user()->workspaceProviders()->findOrFail($attributes['provider_id']);
        if ($provider->isGitHubApp()) {
            abort_unless(filled(config('github-app.webhook_secret')), 503, 'GitHub App webhook delivery is not configured.');
            $attributes['webhook_enabled'] = true;
            $attributes['webhook_secret'] = config('github-app.webhook_secret');
        }
        $repository = $request->user()->workspaceRepositories()->create($attributes);

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
        $provider = $request->user()->workspaceProviders()->findOrFail($validated['provider_id']);
        if ($provider->isGitHubApp()) {
            abort_unless(filled(config('github-app.webhook_secret')), 503, 'GitHub App webhook delivery is not configured.');
            $validated['webhook_enabled'] = true;
            $validated['webhook_secret'] = config('github-app.webhook_secret');
        } elseif ($repository->provider?->isGitHubApp()) {
            $validated['webhook_enabled'] = false;
            $validated['webhook_secret'] = null;
        }
        if (! $update->handle($repository, $validated)) {
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
