<?php

namespace App\Http\Controllers;

use App\Http\Requests\RepositoryRequest;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Website;
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
            'providers' => $request->user()->providers()
                ->forRepositories()
                ->orderBy('name')
                ->get(['id', 'name']),
            'websites' => $request->user()->websites()
                ->orderBy('name')
                ->get(['id', 'name']),
            'statuses' => $this->repositoryStatuses(),
        ]);
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
                        $this->csvCell($repository->website?->server?->name),
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
        return $request->user()->repositories()
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('url', 'like', "%{$value}%")
                        ->orWhere('description', 'like', "%{$value}%");
                });
            })
            ->when($filters['provider_id'], fn ($query, int $id) => $query
                ->where('provider_id', $id))
            ->when($filters['website_id'], fn ($query, int $id) => $query
                ->where('website_id', $id))
            ->when($filters['status'] === 'none', fn ($query) => $query
                ->whereDoesntHave('builds'))
            ->when($filters['status'] && $filters['status'] !== 'none', fn ($query) => $query
                ->whereHas('latestBuild', fn ($query) => $query
                    ->where('status', $filters['status'])));
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
    public function show(Request $request, Repository $repository): View
    {
        $this->authorize('view', $repository);
        $deliveryStatus = $this->deliveryStatus($request);

        return view('scenes.repositories.show', [
            'repository' => $repository,
            'builds' => $repository->builds()->latest()->limit(10)->get(),
            'webhookDeliveries' => $repository->webhookDeliveries()
                ->with('build')
                ->when($deliveryStatus, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')
                ->paginate(10, pageName: 'webhook_page')
                ->appends(array_filter(['delivery_status' => $deliveryStatus])),
            'deliveryStatus' => $deliveryStatus,
            'deliveryStatuses' => RepositoryWebhookDelivery::STATUSES,
            'deploymentInProgress' => $repository->website->hasActiveDeployment(),
            'deploymentReady' => $repository->isDeploymentReady(),
        ]);
    }

    public function exportWebhookDeliveries(Request $request, Repository $repository): StreamedResponse
    {
        $this->authorize('view', $repository);
        $deliveryStatus = $this->deliveryStatus($request);
        $filename = "lessbuild-repository-{$repository->id}-webhook-deliveries-"
            .now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($repository, $deliveryStatus): void {
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

            $repository->webhookDeliveries()
                ->with('build')
                ->when($deliveryStatus, fn ($query, string $status) => $query->where('status', $status))
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
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function deliveryStatus(Request $request): ?string
    {
        $status = $request->string('delivery_status')->toString();

        return in_array($status, RepositoryWebhookDelivery::STATUSES, true) ? $status : null;
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
