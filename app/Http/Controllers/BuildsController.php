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
        $builds = $request->user()
            ->builds()
            ->with('repository.website.server')
            ->latest('builds.created_at')
            ->simplePaginate();

        return view('scenes.builds.index', [
            'builds' => $builds,
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
