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
use App\Support\DateRange;
use App\Support\SqlLike;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepositoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $repositories = $this->filteredRepositories($request, $filters)
            ->with(['provider', 'website.server', 'latestBuild'])
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.repositories.index', [
            'repositories' => $repositories,
            'filters' => $filters,
            'metrics' => $this->indexMetrics($request, $filters),
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
     * @param  array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string}  $filters
     * @return array{total: int, never_deployed: int, active: int, succeeded: int, failed: int, webhooks: int}
     */
    private function indexMetrics(Request $request, array $filters): array
    {
        return [
            'total' => $this->filteredRepositories($request, $filters)->count(),
            'never_deployed' => $this->filteredRepositories($request, $filters)->neverDeployed()->count(),
            'active' => $this->filteredRepositories($request, $filters)
                ->latestBuildStatus(Build::ACTIVE_STATUSES)
                ->count(),
            'succeeded' => $this->filteredRepositories($request, $filters)
                ->latestBuildStatus(Build::STATUS_SUCCEEDED)
                ->count(),
            'failed' => $this->filteredRepositories($request, $filters)
                ->latestBuildStatus(Build::STATUS_FAILED)
                ->count(),
            'webhooks' => $this->filteredRepositories($request, $filters)
                ->where('webhook_enabled', true)
                ->count(),
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);
        $filename = 'lessbuild-repositories-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Repository ID',
                'Name',
                'URL',
                'Branch',
                'Description',
                'Provider',
                'Provider type',
                'Website',
                'Website domain',
                'Server',
                'Latest deployment status',
                'Latest revision',
                'Latest deployment at',
                'Webhook enabled',
                'Created at',
            ], ',', '"', '');

            $this->filteredRepositories($request, $filters)
                ->with(['provider', 'website.server', 'latestBuild'])
                ->latest('repositories.id')
                ->lazy(250)
                ->each(function (Repository $repository) use ($output): void {
                    fputcsv($output, [
                        $repository->id,
                        $this->csvCell($repository->name),
                        $this->csvCell($repository->url),
                        $this->csvCell($repository->branch),
                        $this->csvCell($repository->description),
                        $this->csvCell($repository->provider?->name),
                        $this->csvCell($repository->provider?->provider),
                        $this->csvCell($repository->website?->name),
                        $this->csvCell($repository->website?->url),
                        $this->csvCell($repository->website?->server?->label),
                        $this->csvCell($repository->latestBuild?->status),
                        $this->csvCell($repository->latestBuild?->revision),
                        $repository->latestBuild?->created_at?->toIso8601String(),
                        $repository->webhook_enabled ? 'yes' : 'no',
                        $repository->created_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
     * @param  array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string}  $filters
     */
    private function filteredRepositories(Request $request, array $filters): HasMany
    {
        return $request->user()->workspaceRepositories()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['provider_id'], fn ($query, int $id) => $query
                ->where('provider_id', $id))
            ->when($filters['website_id'], fn ($query, int $id) => $query
                ->where('website_id', $id))
            ->when($filters['status'] === 'none', fn ($query) => $query->neverDeployed())
            ->when($filters['status'] && $filters['status'] !== 'none', fn ($query) => $query
                ->latestBuildStatus($filters['status']));
    }

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

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }

    /**
     * Show the resource
     */
    public function show(
        Request $request,
        Repository $repository,
        DeploymentGate $gate,
        DeploymentPreflight $preflight,
    ): View
    {
        $this->authorize('view', $repository);
        $deliveryFilters = $this->deliveryFilters($request);

        return view('scenes.repositories.show', [
            'repository' => $repository,
            'builds' => $repository->builds()->latest()->limit(10)->get(),
            'deploymentMetrics' => $this->deploymentMetrics($repository),
            'webhookDeliveries' => $this->filteredWebhookDeliveries($repository, $deliveryFilters)
                ->with('build')
                ->latest('id')
                ->paginate(10, pageName: 'webhook_page')
                ->appends(array_filter($deliveryFilters, fn ($value) => $value !== null)),
            'deliveryFilters' => $deliveryFilters,
            'deliveryMetrics' => $this->deliveryMetrics($repository, $deliveryFilters),
            'deliveryStatuses' => RepositoryWebhookDelivery::STATUSES,
            'deploymentInProgress' => $repository->website->hasActiveDeployment(),
            'deploymentReady' => $repository->isDeploymentReady(),
            'deploymentPreflight' => $preflight->assess($repository, $gate->environment($repository)),
            'isFirstDeployment' => ! $repository->builds()->exists(),
        ]);
    }

    /**
     * @param  array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}  $filters
     * @return array{total: int, queued: int, pending: int, unavailable: int, superseded: int, received: int}
     */
    private function deliveryMetrics(Repository $repository, array $filters): array
    {
        $counts = $this->filteredWebhookDeliveries($repository, $filters)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);

        return [
            'total' => $counts->sum(),
            'queued' => $counts->get(RepositoryWebhookDelivery::STATUS_QUEUED, 0),
            'pending' => $counts->get(RepositoryWebhookDelivery::STATUS_PENDING, 0),
            'unavailable' => $counts->get(RepositoryWebhookDelivery::STATUS_UNAVAILABLE, 0),
            'superseded' => $counts->get(RepositoryWebhookDelivery::STATUS_SUPERSEDED, 0),
            'received' => $counts->get(RepositoryWebhookDelivery::STATUS_RECEIVED, 0),
        ];
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

    public function exportWebhookDeliveries(Request $request, Repository $repository): StreamedResponse
    {
        $this->authorize('view', $repository);
        $deliveryFilters = $this->deliveryFilters($request);
        $filename = "lessbuild-repository-{$repository->id}-webhook-deliveries-"
            .now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($repository, $deliveryFilters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Delivery ID',
                'Status',
                'Revision',
                'Commit message',
                'Build ID',
                'Build status',
                'Received at',
                'Updated at',
            ], ',', '"', '');

            $this->filteredWebhookDeliveries($repository, $deliveryFilters)
                ->with('build')
                ->latest('id')
                ->lazy(250)
                ->each(function (RepositoryWebhookDelivery $delivery) use ($output): void {
                    fputcsv($output, [
                        $this->csvCell($delivery->delivery_id),
                        $this->csvCell($delivery->status),
                        $this->csvCell($delivery->revision),
                        $this->csvCell($delivery->commit_message),
                        $delivery->build_id,
                        $this->csvCell($delivery->build?->status),
                        $delivery->created_at?->toIso8601String(),
                        $delivery->updated_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

    /** @param array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string} $filters */
    private function filteredWebhookDeliveries(Repository $repository, array $filters): HasMany
    {
        return $repository->webhookDeliveries()
            ->when($filters['delivery_status'], fn ($query, string $status) => $query
                ->where('status', $status))
            ->when($filters['delivery_date_from'], fn ($query, string $date) => $query
                ->whereDate('created_at', '>=', $date))
            ->when($filters['delivery_date_to'], fn ($query, string $date) => $query
                ->whereDate('created_at', '<=', $date));
    }

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
    public function store(RepositoryRequest $request)
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
    public function update(RepositoryRequest $request, Repository $repository)
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
