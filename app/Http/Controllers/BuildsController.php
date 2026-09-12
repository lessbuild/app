<?php

namespace App\Http\Controllers;

use App\Actions\Repository\ApproveBuildAction;
use App\Actions\Repository\CancelQueuedDeploymentAction;
use App\Actions\Repository\CancelRunningDeploymentAction;
use App\Actions\Repository\RedeployBuildAction;
use App\Actions\Repository\RejectBuildAction;
use App\Actions\Repository\RollbackBuildAction;
use App\Actions\Repository\UpdateBuildNoteAction;
use App\Data\BuildRedeploymentResult;
use App\Http\Requests\BuildApprovalRequest;
use App\Http\Requests\BuildIndexRequest;
use App\Http\Requests\BuildNoteRequest;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Build;
use App\Services\BuildInventoryExporter;
use App\Services\BuildInventoryQuery;
use App\Services\DeploymentRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BuildsController extends Controller
{
    public function __construct(
        private readonly BuildInventoryExporter $buildInventoryExporter,
        private readonly BuildInventoryQuery $buildInventory,
        private readonly ApproveBuildAction $approveBuild,
        private readonly RejectBuildAction $rejectBuild,
    ) {}

    /**
     * Show resources in storage
     */
    public function index(BuildIndexRequest $request): View
    {
        $filters = $request->filters();

        $builds = $this->buildInventory->for($request->user(), $filters)
            ->latest('builds.created_at')
            ->simplePaginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.builds.index', [
            'builds' => $builds,
            'filters' => $filters,
            'metrics' => $this->buildInventory->metrics($request->user(), $filters),
            'repositories' => $request->user()->workspaceRepositories()->orderBy('name')->get(['id', 'name']),
            'websites' => $request->user()->workspaceWebsites()->orderBy('name')->get(['id', 'name']),
            'servers' => $request->user()->workspaceServers()->orderBy('name')->get(['id', 'name', 'display_name']),
            'providers' => $request->user()->workspaceProviders()->forRepositories()->orderBy('name')->get(['id', 'name']),
            'statuses' => $this->statuses(),
            'triggers' => $this->triggers(),
        ]);
    }

    /**
     * Stream filtered workspace deployment history, release provenance, and operator notes as private, spreadsheet-safe CSV.
     */
    public function export(BuildIndexRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->buildInventoryExporter->stream($request->user(), $filters);
    }

    /**
     * Authorize build visibility and render its deployment page with repository and infrastructure context.
     */
    public function show(Build $build): View
    {
        $this->authorize('view', $build);
        $build->load('repository.website.server');

        return view('scenes.builds.show', [
            'build' => $build,
        ]);
    }

    /**
     * Authorize two distinct builds in the same repository and render their comparison with a nullable duration delta.
     */
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

    /**
     * Authorize build visibility and return its deployment log as plain text, or fail with 404 when no log exists.
     */
    public function downloadLog(Build $build, PlainTextLogDownload $download): Response
    {
        $this->authorize('view', $build);

        $log = $build->logs()
            ->where('type', Build::DEPLOYMENT_LOG_TYPE)
            ->firstOrFail();
        $filename = "lessbuild-build-{$build->id}-deployment.log";

        return $download->make($log->log, $filename);
    }

    /**
     * Authorize cancellation of queued work or its matching remote process while preserving any partial log.
     *
     * @return RedirectResponse The cancellation outcome, including concurrent completion or remote failure.
     */
    public function cancel(
        Build $build,
        CancelQueuedDeploymentAction $cancelQueued,
        CancelRunningDeploymentAction $cancelRunning,
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
            $canceled = $cancelRunning->handle($build);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('The deployment could not be canceled. Please try again.'));
        }

        return $canceled
            ? back()->with('success', __('Deployment canceled.'))
            : back()->with('info', __('The deployment finished before it could be canceled.'));
    }

    /**
     * Authorize reuse of a completed build and redirect to the queued redeployment or its blocking reason.
     */
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

    /**
     * Validate an optional approval note and atomically approve an authorized build that is still eligible and awaiting review.
     *
     * @return RedirectResponse The dispatched deployment result or its current eligibility failure.
     */
    public function approve(BuildApprovalRequest $request, Build $build, DeploymentRequest $deployments): RedirectResponse
    {
        $approved = $this->approveBuild->handle($build, $request->user(), $request->approvalNote());

        if (! $approved) {
            return back()->with('info', __('This deployment is no longer awaiting approval, its infrastructure is unavailable, or an environment policy blocks it.'));
        }

        $deployments->dispatch($approved);

        return back()->with('success', __('Deployment approved and queued.'));
    }

    /**
     * Authorize restoration of the bound release and redirect to its queued rollback or the blocking reason.
     */
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

    /**
     * Validate an optional approval note and atomically reject an authorized build still awaiting review.
     *
     * @return RedirectResponse The rejection acknowledgement or a concurrent-state notice.
     */
    public function reject(BuildApprovalRequest $request, Build $build): RedirectResponse
    {
        $rejected = $this->rejectBuild->handle($build, $request->user(), $request->approvalNote());

        return $rejected
            ? back()->with('success', __('Deployment rejected.'))
            : back()->with('info', __('This deployment is no longer awaiting approval.'));
    }

    /**
     * Validate and normalize the authorized build's operator note, recording activity only when its value changes.
     */
    public function updateNote(BuildNoteRequest $request, Build $build, UpdateBuildNoteAction $updateNote): RedirectResponse
    {
        $note = $request->note();

        if (! $updateNote->handle($build, $request->user(), $note)) {
            return back()->with('info', __('Deployment note is unchanged.'));
        }

        return back()->with('success', $note === null
            ? __('Deployment note cleared.')
            : __('Deployment note saved.'));
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
}
