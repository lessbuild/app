<?php

namespace App\Http\Controllers;

use App\Http\Requests\RepositoryRequest;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Website;
use App\Services\DeploymentGate;
use App\Services\DeploymentPreflight;
use App\Services\DeploymentRequest;
use App\Services\RepositoryInventoryExporter;
use App\Services\RepositoryInventoryQuery;
use App\Services\RepositoryWebhookDeliveryHistoryExporter;
use App\Services\RepositoryWebhookDeliveryHistoryQuery;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ): View {
        $this->authorize('view', $repository);
        $deliveryFilters = $this->deliveryFilters($request);

        return view('scenes.repositories.show', [
            'repository' => $repository,
            'builds' => $repository->builds()->latest()->limit(10)->get(),
            'deploymentMetrics' => $this->deploymentMetrics($repository),
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

    /** @return array{total: int, succeeded: int, failed: int, success_rate: ?int, median_duration_seconds: ?int, duration_sample_size: int} */
    private function deploymentMetrics(Repository $repository): array
    {
        $counts = $repository->builds()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);
        $succeeded = $counts->get(Build::STATUS_SUCCEEDED, 0);
        $failed = $counts->get(Build::STATUS_FAILED, 0);
        $completed = $succeeded + $failed;
        $durations = $repository->builds()
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'started_at', 'finished_at'])
            ->map(fn (Build $build): ?int => $build->durationSeconds())
            ->filter(fn (?int $duration): bool => $duration !== null)
            ->sort()
            ->values();
        $durationCount = $durations->count();
        $middle = intdiv($durationCount, 2);
        $median = match (true) {
            $durationCount === 0 => null,
            $durationCount % 2 === 1 => $durations[$middle],
            default => intdiv($durations[$middle - 1] + $durations[$middle], 2),
        };

        return [
            'total' => (int) $counts->sum(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'success_rate' => $completed > 0 ? (int) round(($succeeded / $completed) * 100) : null,
            'median_duration_seconds' => $median,
            'duration_sample_size' => $durationCount,
        ];
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
    public function update(RepositoryRequest $request, Repository $repository): RedirectResponse
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
    public function destroy(Repository $repository): RedirectResponse
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
    public function deploy(Request $request, Repository $repository, DeploymentRequest $deployments, DeploymentGate $gate): RedirectResponse
    {
        $this->authorize('deploy', $repository);

        if (! $repository->isDeploymentReady()) {
            return back()->with('error', 'The website and server must be active before deployment.');
        }
        if ($reason = $gate->blockReason($repository)) {
            return back()->with('error', $reason);
        }

        $build = DB::transaction(function () use ($repository, $request, $deployments): ?Build {
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
                'trigger_source' => Build::TRIGGER_MANUAL,
                ...$deployments->attributes($lockedRepository, $request->user()),
            ]);
        });

        if (! $build) {
            return back()->with('info', 'A deployment is already in progress');
        }

        $deployments->dispatch($build);

        return redirect()->route('builds.show', $build)->with('success', $build->status === Build::STATUS_AWAITING_APPROVAL
            ? 'Deployment submitted for approval'
            : 'Deployment queued');
    }
}
