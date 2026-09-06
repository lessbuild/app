<?php

namespace App\Http\Controllers;

use App\Actions\Repository\CancelDeploymentAction;
use App\Actions\Repository\CancelQueuedDeploymentAction;
use App\Actions\Repository\RedeployBuildAction;
use App\Actions\Repository\RollbackBuildAction;
use App\Data\BuildRedeploymentResult;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Build;
use App\Notifications\NotificationInbox;
use App\Services\ActivityRecorder;
use App\Services\DeploymentGate;
use App\Services\DeploymentRequest;
use App\Services\Runner;
use App\Support\DateRange;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BuildsController extends Controller
{
    /**
     * Show resources in storage
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $builds = $this->filteredBuilds($request, $filters)
            ->latest('builds.created_at')
            ->simplePaginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.builds.index', [
            'builds' => $builds,
            'filters' => $filters,
            'metrics' => $this->metrics($request, $filters),
            'repositories' => $request->user()->workspaceRepositories()->orderBy('name')->get(['id', 'name']),
            'websites' => $request->user()->workspaceWebsites()->orderBy('name')->get(['id', 'name']),
            'servers' => $request->user()->workspaceServers()->orderBy('name')->get(['id', 'name', 'display_name']),
            'providers' => $request->user()->workspaceProviders()->forRepositories()->orderBy('name')->get(['id', 'name']),
            'statuses' => $this->statuses(),
            'triggers' => $this->triggers(),
        ]);
    }

    /**
     * @param  array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, active: int, succeeded: int, failed: int, success_rate: ?int, latest_at: CarbonInterface|null}
     */
    private function metrics(Request $request, array $filters): array
    {
        $succeeded = $this->filteredBuilds($request, $filters)
            ->where('builds.status', Build::STATUS_SUCCEEDED)
            ->count();
        $failed = $this->filteredBuilds($request, $filters)
            ->where('builds.status', Build::STATUS_FAILED)
            ->count();
        $completed = $succeeded + $failed;
        $latest = $this->filteredBuilds($request, $filters)
            ->withoutEagerLoads()
            ->select(['builds.id', 'builds.created_at'])
            ->latest('builds.created_at')
            ->latest('builds.id')
            ->first();

        return [
            'total' => $this->filteredBuilds($request, $filters)->count(),
            'active' => $this->filteredBuilds($request, $filters)
                ->whereIn('builds.status', Build::ACTIVE_STATUSES)
                ->count(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'success_rate' => $completed > 0 ? (int) round(($succeeded / $completed) * 100) : null,
            'latest_at' => $latest?->created_at,
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'lessbuild-builds-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Build ID',
                'Repository',
                'Website',
                'Server',
                'Status',
                'Trigger',
                'Revision',
                'Commit message',
                'Operator note',
                'Promoted from build',
                'Promotion note',
                'Created at',
                'Started at',
                'Finished at',
                'Duration seconds',
            ], ',', '"', '');

            $this->filteredBuilds($request, $filters)
                ->latest('builds.id')
                ->lazy(250)
                ->each(function (Build $build) use ($output): void {
                    $repository = $build->repository;
                    $website = $repository->website;
                    fputcsv($output, [
                        $build->id,
                        $this->csvCell($repository->name),
                        $this->csvCell($website?->name),
                        $this->csvCell($website?->server?->label),
                        $build->status,
                        $build->trigger_source,
                        $build->revision,
                        $this->csvCell($build->commit_message),
                        $this->csvCell($build->operator_note),
                        $build->promoted_from_build_id,
                        $this->csvCell($build->promotion_note),
                        $build->created_at?->toIso8601String(),
                        $build->started_at?->toIso8601String(),
                        $build->finished_at?->toIso8601String(),
                        $build->durationSeconds(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Build $build): View
    {
        $this->authorize('view', $build);
        $build->load('repository.website.server');

        return view('scenes.builds.show', [
            'build' => $build,
        ]);
    }

    public function compare(Build $build, Build $baseline): View
    {
        $this->authorize('view', $build);
        $this->authorize('view', $baseline);
        abort_unless($build->id !== $baseline->id && $build->repository_id === $baseline->repository_id, 404);

        $build->load('repository.website.server');
        $baseline->load('repository.website.server');
        $buildDuration = $build->durationSeconds();
        $baselineDuration = $baseline->durationSeconds();

        return view('scenes.builds.compare', [
            'build' => $build,
            'baseline' => $baseline,
            'durationDelta' => $buildDuration !== null && $baselineDuration !== null
                ? $buildDuration - $baselineDuration
                : null,
        ]);
    }

    public function downloadLog(Build $build, PlainTextLogDownload $download): Response
    {
        $this->authorize('view', $build);

        $log = $build->logs()
            ->where('type', Build::DEPLOYMENT_LOG_TYPE)
            ->firstOrFail();
        $filename = "lessbuild-build-{$build->id}-deployment.log";

        return $download->make($log->log, $filename);
    }

    public function cancel(
        Build $build,
        Runner $runner,
        CancelQueuedDeploymentAction $cancelQueued,
    ): RedirectResponse {
        $this->authorize('cancel', $build);

        if (in_array($build->status, [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL], true)) {
            return $cancelQueued->handle($build)
                ? back()->with('success', __('Queued deployment canceled.'))
                : back()->with('info', __('This deployment is no longer cancellable.'));
        }

        if ($build->status !== Build::STATUS_RUNNING || ! $build->remote_process_id || ! $build->remote_process_path) {
            return back()->with('info', __('This deployment is no longer cancellable.'));
        }

        try {
            $partialLog = (new CancelDeploymentAction($build, $runner))->handle();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('The deployment could not be canceled. Please try again.'));
        }

        $canceled = DB::transaction(function () use ($build, $partialLog): bool {
            $locked = Build::query()
                ->whereKey($build->id)
                ->where('status', Build::STATUS_RUNNING)
                ->where('remote_process_id', $build->remote_process_id)
                ->where('remote_process_path', $build->remote_process_path)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if ($partialLog !== null) {
                $locked->logs()->updateOrCreate(
                    ['type' => Build::DEPLOYMENT_LOG_TYPE],
                    ['log' => $partialLog],
                );
            }

            $locked->update([
                'status' => Build::STATUS_CANCELED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => null,
            ]);

            return true;
        });

        return $canceled
            ? back()->with('success', __('Deployment canceled.'))
            : back()->with('info', __('The deployment finished before it could be canceled.'));
    }

    public function redeploy(Build $build, RedeployBuildAction $redeploy): RedirectResponse
    {
        $this->authorize('redeploy', $build);

        $result = $redeploy->handle($build, request()->user());

        return match ($result->status) {
            BuildRedeploymentResult::QUEUED => redirect()
                ->route('builds.show', $result->build)
                ->with('success', __('Redeployment queued.')),
            BuildRedeploymentResult::UNAVAILABLE => back()
                ->with('error', __('The website and server must be active before redeployment.')),
            BuildRedeploymentResult::ACTIVE => back()
                ->with('info', __('A deployment is already in progress.')),
            default => back()
                ->with('info', __('Only completed, failed, or canceled deployments can be redeployed.')),
        };
    }

    public function approve(Request $request, Build $build, DeploymentRequest $deployments, DeploymentGate $gate, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('approve', $build);
        $validated = $request->validateWithBag('approval', [
            'approval_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $approved = DB::transaction(function () use ($build, $request, $validated, $gate, $activity): ?Build {
            $locked = Build::query()
                ->whereKey($build->id)
                ->where('status', Build::STATUS_AWAITING_APPROVAL)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->repository->isDeploymentReady() || $gate->blockReason($locked->repository)) {
                return null;
            }

            $locked->update([
                'status' => Build::STATUS_QUEUED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_note' => filled($validated['approval_note'] ?? null)
                    ? trim($validated['approval_note'])
                    : null,
            ]);
            $this->acknowledgeApprovalNotifications($locked);
            $activity->record($locked, $request->user()->id, 'deployment', $locked->trigger_source === Build::TRIGGER_PROMOTION
                ? 'Release promotion was approved.'
                : 'Deployment was approved.');

            return $locked;
        });

        if (! $approved) {
            return back()->with('info', __('This deployment is no longer awaiting approval, its infrastructure is unavailable, or an environment policy blocks it.'));
        }

        $deployments->dispatch($approved);

        return back()->with('success', __('Deployment approved and queued.'));
    }

    public function rollback(Request $request, Build $build, RollbackBuildAction $rollback): RedirectResponse
    {
        $this->authorize('rollback', $build);
        $result = $rollback->handle($build, $request->user());

        return match ($result->status) {
            BuildRedeploymentResult::QUEUED => redirect()
                ->route('builds.show', $result->build)
                ->with('success', __('Instant rollback queued.')),
            BuildRedeploymentResult::UNAVAILABLE => back()
                ->with('error', __('The website and server must be active before rollback.')),
            BuildRedeploymentResult::ACTIVE => back()
                ->with('info', __('A deployment is already in progress.')),
            default => back()
                ->with('info', __('This release artifact is not available for instant rollback.')),
        };
    }

    public function reject(Request $request, Build $build, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('approve', $build);
        $validated = $request->validateWithBag('approval', [
            'approval_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $rejected = DB::transaction(function () use ($build, $request, $validated, $activity): bool {
            $locked = Build::query()->whereKey($build->id)->where('status', Build::STATUS_AWAITING_APPROVAL)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }
            $locked->update([
                'status' => Build::STATUS_REJECTED,
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'finished_at' => now(),
                'approval_note' => filled($validated['approval_note'] ?? null)
                    ? trim($validated['approval_note'])
                    : null,
            ]);
            $this->acknowledgeApprovalNotifications($locked);
            $activity->record($locked, $request->user()->id, 'deployment', $locked->trigger_source === Build::TRIGGER_PROMOTION
                ? 'Release promotion was rejected.'
                : 'Deployment was rejected.');

            return true;
        });

        return $rejected
            ? back()->with('success', __('Deployment rejected.'))
            : back()->with('info', __('This deployment is no longer awaiting approval.'));
    }

    public function updateNote(Request $request, Build $build, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('updateNote', $build);
        $validated = $request->validateWithBag('buildNote', [
            'operator_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = trim($validated['operator_note'] ?? '');
        $note = $note === '' ? null : $note;

        if ($build->operator_note === $note) {
            return back()->with('info', __('Deployment note is unchanged.'));
        }

        $build->update(['operator_note' => $note]);
        $activity->record(
            $build,
            $request->user()->id,
            'deployment',
            $note === null ? 'Deployment note was cleared.' : 'Deployment note was updated.',
        );

        return back()->with('success', $note === null
            ? __('Deployment note cleared.')
            : __('Deployment note saved.'));
    }

    /** @return array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $trigger = $request->string('trigger')->toString();
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $repositoryId = filter_var($request->query('repository_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $websiteId = filter_var($request->query('website_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $serverId = filter_var($request->query('server_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $providerId = filter_var($request->query('provider_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return [
            'repository_id' => $repositoryId ?: null,
            'website_id' => $websiteId ?: null,
            'server_id' => $serverId ?: null,
            'provider_id' => $providerId ?: null,
            'status' => in_array($status, $this->statuses(), true) ? $status : null,
            'trigger' => in_array($trigger, $this->triggers(), true) ? $trigger : null,
            'search' => $search !== '' ? $search : null,
            'active' => $request->boolean('active') ? '1' : null,
            'latest' => $request->boolean('latest') ? '1' : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredBuilds(Request $request, array $filters): Builder
    {
        return Build::query()
            ->whereHas('repository', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))
            ->with('repository.website.server')
            ->when($filters['repository_id'], fn ($query, int $id) => $query
                ->where('builds.repository_id', $id))
            ->when($filters['website_id'], fn ($query, int $id) => $query
                ->whereHas('repository', fn ($query) => $query->where('website_id', $id)))
            ->when($filters['server_id'], fn ($query, int $id) => $query
                ->whereHas('repository.website', fn ($query) => $query->where('server_id', $id)))
            ->when($filters['provider_id'], fn ($query, int $id) => $query
                ->whereHas('repository', fn ($query) => $query->where('provider_id', $id)))
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('builds.status', $value))
            ->when($filters['trigger'], fn ($query, string $value) => $query
                ->where('builds.trigger_source', $value))
            ->when($filters['active'], fn ($query) => $query
                ->whereIn('builds.status', Build::ACTIVE_STATUSES))
            ->when($filters['latest'], fn ($query) => $query
                ->whereIn('builds.id', Build::query()
                    ->selectRaw('MAX(id)')
                    ->groupBy('repository_id')))
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("builds.revision LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("builds.commit_message LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("builds.operator_note LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('repository', fn ($query) => $query
                            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            })
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('builds.created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('builds.created_at', '<=', $date));
    }

    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return array_values(array_unique(array_merge(Build::ACTIVE_STATUSES, Build::TERMINAL_STATUSES)));
    }

    /** @return list<string> */
    private function triggers(): array
    {
        return [Build::TRIGGER_MANUAL, Build::TRIGGER_WEBHOOK, Build::TRIGGER_REDEPLOY, Build::TRIGGER_ROLLBACK, Build::TRIGGER_SCHEDULED, Build::TRIGGER_API, Build::TRIGGER_PROMOTION];
    }

    private function acknowledgeApprovalNotifications(Build $build): void
    {
        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('data->category', 'deployment')
            ->where('data->resource_id', $build->id)
            ->where('data->status', NotificationInbox::STATUS_INFO)
            ->update(['read_at' => now()]);
    }

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
