<?php

namespace App\Http\Controllers;

use App\Actions\Repository\CancelDeploymentAction;
use App\Actions\Repository\CancelQueuedDeploymentAction;
use App\Actions\Repository\RedeployBuildAction;
use App\Data\BuildRedeploymentResult;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Build;
use App\Services\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'repositories' => $request->user()->repositories()->orderBy('name')->get(['id', 'name']),
            'statuses' => $this->statuses(),
            'triggers' => $this->triggers(),
        ]);
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
                    $duration = $build->started_at && $build->finished_at
                        ? $build->started_at->diffInSeconds($build->finished_at)
                        : null;

                    fputcsv($output, [
                        $build->id,
                        $this->csvCell($repository->name),
                        $this->csvCell($website?->name),
                        $this->csvCell($website?->server?->label),
                        $build->status,
                        $build->trigger_source,
                        $build->revision,
                        $this->csvCell($build->commit_message),
                        $build->created_at?->toIso8601String(),
                        $build->started_at?->toIso8601String(),
                        $build->finished_at?->toIso8601String(),
                        $duration,
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
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

        if ($build->status === Build::STATUS_QUEUED) {
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

        $result = $redeploy->handle($build);

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

    /** @return array{repository_id: ?int, status: ?string, trigger: ?string, search: ?string, latest: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $trigger = $request->string('trigger')->toString();
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $repositoryId = filter_var($request->query('repository_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return [
            'repository_id' => $repositoryId ?: null,
            'status' => in_array($status, $this->statuses(), true) ? $status : null,
            'trigger' => in_array($trigger, $this->triggers(), true) ? $trigger : null,
            'search' => $search !== '' ? $search : null,
            'latest' => $request->boolean('latest') ? '1' : null,
            'date_from' => $this->date($request->string('date_from')->toString()),
            'date_to' => $this->date($request->string('date_to')->toString()),
        ];
    }

    /** @param array{repository_id: ?int, status: ?string, trigger: ?string, search: ?string, latest: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredBuilds(Request $request, array $filters): HasManyThrough
    {
        return $request->user()->builds()
            ->with('repository.website.server')
            ->when($filters['repository_id'], fn ($query, int $id) => $query
                ->where('builds.repository_id', $id))
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('builds.status', $value))
            ->when($filters['trigger'], fn ($query, string $value) => $query
                ->where('builds.trigger_source', $value))
            ->when($filters['latest'], fn ($query) => $query
                ->whereIn('builds.id', Build::query()
                    ->selectRaw('MAX(id)')
                    ->groupBy('repository_id')))
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('builds.revision', 'like', "%{$value}%")
                        ->orWhere('builds.commit_message', 'like', "%{$value}%")
                        ->orWhereHas('repository', fn ($query) => $query
                            ->where('name', 'like', "%{$value}%"));
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
        return [Build::TRIGGER_MANUAL, Build::TRIGGER_WEBHOOK, Build::TRIGGER_REDEPLOY];
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
