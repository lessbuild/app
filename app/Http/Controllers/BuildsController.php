<?php

namespace App\Http\Controllers;

use App\Actions\Repository\CancelDeploymentAction;
use App\Models\Build;
use App\Services\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $build->load(['repository.website.server', 'logs']);

        return view('scenes.builds.show', [
            'build' => $build,
            'deploymentLog' => $build->logs->firstWhere('type', 'deployment'),
        ]);
    }

    public function cancel(Build $build, Runner $runner): RedirectResponse
    {
        $this->authorize('cancel', $build);

        if ($build->status !== Build::STATUS_RUNNING || ! $build->remote_process_id || ! $build->remote_process_path) {
            return back()->with('info', __('This deployment is no longer running.'));
        }

        try {
            (new CancelDeploymentAction($build, $runner))->handle();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('The deployment could not be canceled. Please try again.'));
        }

        $canceled = Build::query()
            ->whereKey($build->id)
            ->where('status', Build::STATUS_RUNNING)
            ->where('remote_process_id', $build->remote_process_id)
            ->where('remote_process_path', $build->remote_process_path)
            ->update([
                'status' => Build::STATUS_CANCELED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => null,
            ]);

        return $canceled === 1
            ? back()->with('success', __('Deployment canceled.'))
            : back()->with('info', __('The deployment finished before it could be canceled.'));
    }
}
