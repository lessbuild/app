<?php

namespace App\Http\Controllers;

use App\Actions\Automation\CreateDeploymentScheduleAction;
use App\Actions\Automation\CreateScalingScheduleAction;
use App\Actions\Automation\CreateScheduledTaskAction;
use App\Actions\Automation\DeleteDeploymentScheduleAction;
use App\Actions\Automation\DeleteScalingScheduleAction;
use App\Actions\Automation\DeleteScheduledTaskAction;
use App\Actions\Automation\QueueScheduledTaskRunAction;
use App\Http\Requests\StoreDeploymentScheduleRequest;
use App\Http\Requests\StoreScalingScheduleRequest;
use App\Http\Requests\StoreScheduledTaskRequest;
use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\Entitlements;
use App\Services\WorkflowConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class AutomationController extends Controller
{
    /**
     * Use workspace entitlements to gate automation features and API credential issuance.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Render current-workspace automation, recent scheduled-task runs, personal tokens, and feature availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;

        return view('automation.index', [
            'projects' => $organization->projects()->with(['environments.deploymentSchedules', 'environments.scalingSchedules', 'environments.scheduledTasks.runs' => fn ($query) => $query->latest()->limit(10)])->orderBy('name')->get(),
            'tokens' => $request->user()->tokens()->latest()->get(),
            'canManage' => $organization->permits($request->user(), 'manage'),
            'features' => collect(['api', 'scheduled_deployments', 'scaling', 'scheduled_scaling', 'hibernation'])->mapWithKeys(fn ($feature) => [$feature => $this->entitlements->allows($organization, $feature)]),
        ]);
    }

    /**
     * Validate workflow text for an editable project and redirect after its atomic application.
     */
    public function workflow(Request $request, Project $project, WorkflowConfiguration $workflow): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['workflow' => ['required', 'string', 'max:50000']]);
        $workflow->apply($project, $data['workflow'], $request->user()->id);

        return back()->with('success', __('Workflow applied atomically.'));
    }

    /**
     * Validate cron timing for an editable, entitled environment, then create an enabled deployment schedule.
     */
    public function deploymentSchedule(StoreDeploymentScheduleRequest $request, Environment $environment, CreateDeploymentScheduleAction $createSchedule): RedirectResponse
    {
        $createSchedule->handle($environment, $request->user(), $request->validated());

        return back()->with('success', __('Deployment schedule created.'));
    }

    /**
     * Authorize updates to the schedule's environment, delete the schedule, and redirect back.
     */
    public function destroyDeploymentSchedule(DeploymentSchedule $schedule, DeleteDeploymentScheduleAction $deleteSchedule): RedirectResponse
    {
        $this->authorize('update', $schedule->environment);
        $deleteSchedule->handle($schedule);

        return back()->with('success', __('Deployment schedule deleted.'));
    }

    /**
     * Validate cron timing and replicas within an entitled environment's limits, then save an enabled schedule.
     */
    public function scalingSchedule(StoreScalingScheduleRequest $request, Environment $environment, CreateScalingScheduleAction $createSchedule): RedirectResponse
    {
        $createSchedule->handle($environment, $request->user(), $request->validated());

        return back()->with('success', __('Scaling schedule created.'));
    }

    /**
     * Authorize updates to the schedule's environment, delete the scaling schedule, and redirect back.
     */
    public function destroyScalingSchedule(ScalingSchedule $schedule, DeleteScalingScheduleAction $deleteSchedule): RedirectResponse
    {
        $this->authorize('update', $schedule->environment);
        $deleteSchedule->handle($schedule);

        return back()->with('success', __('Scaling schedule deleted.'));
    }

    /**
     * Validate replica bounds and optional idle timeout, update the authorized environment, and queue its running state.
     */
    public function scale(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'scaling');
        $data = $request->validate([
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20', 'gte:minimum_replicas'],
            'desired_replicas' => ['required', 'integer', 'gte:minimum_replicas', 'lte:maximum_replicas'],
            'hibernate_after_minutes' => ['nullable', 'integer', Rule::in([5, 15, 30, 60, 120, 1440])],
        ]);
        $environment->update([...$data, 'hibernated_at' => null]);
        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, false);

        return back()->with('success', __('Scaling change queued.'));
    }

    /**
     * Validate running or hibernated state for an editable environment and redirect after queuing the transition.
     */
    public function runtime(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $data = $request->validate(['state' => ['required', Rule::in(['running', 'hibernated'])]]);
        if ($data['state'] === 'hibernated') {
            $this->entitlements->enforce($environment->project->organization, 'hibernation');
        }
        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, $data['state'] === 'hibernated');

        return back()->with('success', __('Runtime state change queued.'));
    }

    /**
     * Validate cron timing, command, timeout, overlap, and alert options before saving an entitled environment task.
     */
    public function scheduledTask(StoreScheduledTaskRequest $request, Environment $environment, CreateScheduledTaskAction $createTask): RedirectResponse
    {
        $createTask->handle($environment, $request->user(), $request->validated());

        return back()->with('success', __('Scheduled task created.'));
    }

    /**
     * Queue an authorized, entitled task unless its non-overlap policy detects an active run.
     *
     * @return RedirectResponse A queued acknowledgement or an already-running validation error.
     */
    public function runScheduledTask(ScheduledTask $task, QueueScheduledTaskRunAction $queueRun): RedirectResponse
    {
        $this->authorize('update', $task->environment);
        if ($queueRun->handle($task) === null) {
            return back()->withErrors(['task' => __('This task is already running.')]);
        }

        return back()->with('success', __('Task queued.'));
    }

    /**
     * Authorize updates to the task's environment, delete the task, and redirect back.
     */
    public function destroyScheduledTask(ScheduledTask $task, DeleteScheduledTaskAction $deleteTask): RedirectResponse
    {
        $this->authorize('update', $task->environment);
        $deleteTask->handle($task);

        return back()->with('success', __('Scheduled task deleted.'));
    }

    /**
     * Authorize viewing the run's environment and return uncached plain-text output or an empty-output message.
     */
    public function scheduledTaskOutput(ScheduledTaskRun $run): Response
    {
        $this->authorize('view', $run->task->environment);

        return response($run->output ?: __('No output was recorded.'), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Validate token name, allowed abilities, and expiry for an entitled workspace owner.
     *
     * @return RedirectResponse The new plaintext credential flashed for one-time display.
     */
    public function token(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->owner->is($request->user()), 403);
        $this->entitlements->enforce($organization, 'api');
        $request->mergeIfMissing(['expires_in_days' => 365]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(['read', 'deploy', 'manage'])],
            'expires_in_days' => ['required', 'integer', Rule::in([30, 90, 180, 365])],
        ]);
        $token = $request->user()->createToken(
            $data['name'],
            array_values(array_unique($data['abilities'])),
            now()->addDays($data['expires_in_days']),
        );

        return back()->with('success', __('API token created. Copy it now; it will not be shown again.'))->with('plainTextToken', $token->plainTextToken);
    }

    /**
     * Require ownership of the route-bound personal token, revoke it, and redirect with an acknowledgement.
     */
    public function destroyToken(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $this->ensureOwnedToken($request, $token);
        $token->delete();

        return back()->with('success', __('API token revoked.'));
    }

    /**
     * Require an entitled workspace owner and owned token before replacing it with matching abilities.
     *
     * @return RedirectResponse The replacement plaintext token; the previous credential is revoked.
     */
    public function rotateToken(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->owner->is($request->user()), 403);
        $this->entitlements->enforce($organization, 'api');
        $this->ensureOwnedToken($request, $token);
        $replacement = $request->user()->createToken($token->name, $token->abilities, now()->addYear());
        $token->delete();

        return back()->with('success', __('API token rotated. The previous token has been revoked.'))
            ->with('plainTextToken', $replacement->plainTextToken);
    }

    /**
     * @param  Request  $request  The authenticated token owner's request.
     * @param  PersonalAccessToken  $token  The route-bound Sanctum token.
     * @return void Reject tokens owned by another user or authenticatable model with a 404.
     */
    private function ensureOwnedToken(Request $request, PersonalAccessToken $token): void
    {
        $user = $request->user();
        abort_unless(
            (string) $token->tokenable_id === (string) $user->getKey()
                && $token->tokenable_type === $user->getMorphClass(),
            404,
        );
    }
}
