<?php

namespace App\Http\Controllers;

use App\Actions\Repository\ApproveBuildAction;
use App\Actions\Repository\CancelDeploymentAction;
use App\Actions\Repository\CancelQueuedDeploymentAction;
use App\Actions\Repository\RedeployBuildAction;
use App\Actions\Repository\RejectBuildAction;
use App\Actions\Repository\RollbackBuildAction;
use App\Data\BuildRedeploymentResult;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Build;
use App\Services\ActivityRecorder;
use App\Services\BuildInventoryExporter;
use App\Services\BuildInventoryQuery;
use App\Services\DeploymentRequest;
use App\Services\Runner;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

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
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

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
    public function approve(Request $request, Build $build, DeploymentRequest $deployments): RedirectResponse
    {
        $this->authorize('approve', $build);
        $validated = $request->validateWithBag('approval', [
            'approval_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $approved = $this->approveBuild->handle($build, $request->user(), $validated['approval_note'] ?? null);

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
    public function reject(Request $request, Build $build): RedirectResponse
    {
        $this->authorize('approve', $build);
        $validated = $request->validateWithBag('approval', [
            'approval_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $rejected = $this->rejectBuild->handle($build, $request->user(), $validated['approval_note'] ?? null);

        return $rejected
            ? back()->with('success', __('Deployment rejected.'))
            : back()->with('info', __('This deployment is no longer awaiting approval.'));
    }

    /**
     * Validate and normalize the authorized build's operator note, recording activity only when its value changes.
     */
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

    /**
     * Return an unchanged valid Y-m-d calendar date, or null for malformed or overflowing input.
     */
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
}
