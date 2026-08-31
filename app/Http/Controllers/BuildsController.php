<?php

namespace App\Http\Controllers;

use App\Actions\Repository\CancelDeploymentAction;
use App\Actions\Repository\CancelQueuedDeploymentAction;
use App\Actions\Repository\RedeployBuildAction;
use App\Data\BuildRedeploymentResult;
use App\Models\Build;
use App\Services\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuildsController extends Controller
{
    /**
     * Show resources in storage
     */
    public function index(Request $request): View
    {
        $statuses = array_values(array_unique(array_merge(Build::ACTIVE_STATUSES, Build::TERMINAL_STATUSES)));
        $triggers = [Build::TRIGGER_MANUAL, Build::TRIGGER_WEBHOOK, Build::TRIGGER_REDEPLOY];
        $status = $request->string('status')->toString();
        $trigger = $request->string('trigger')->toString();
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $repositoryId = filter_var($request->query('repository_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $filters = [
            'repository_id' => $repositoryId ?: null,
            'status' => in_array($status, $statuses, true) ? $status : null,
            'trigger' => in_array($trigger, $triggers, true) ? $trigger : null,
            'search' => $search !== '' ? $search : null,
        ];

        $builds = $request->user()->builds()
            ->with('repository.website.server')
            ->when($filters['repository_id'], fn ($query, int $id) => $query
                ->where('builds.repository_id', $id))
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('builds.status', $value))
            ->when($filters['trigger'], fn ($query, string $value) => $query
                ->where('builds.trigger_source', $value))
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('builds.revision', 'like', "%{$value}%")
                        ->orWhere('builds.commit_message', 'like', "%{$value}%")
                        ->orWhereHas('repository', fn ($query) => $query
                            ->where('name', 'like', "%{$value}%"));
                });
            })
            ->latest('builds.created_at')
            ->simplePaginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.builds.index', [
            'builds' => $builds,
            'filters' => $filters,
            'repositories' => $request->user()->repositories()->orderBy('name')->get(['id', 'name']),
            'statuses' => $statuses,
            'triggers' => $triggers,
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
}
