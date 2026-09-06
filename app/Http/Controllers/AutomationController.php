<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Jobs\RunScheduledTaskJob;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\Entitlements;
use App\Services\WorkflowConfiguration;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AutomationController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

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

    public function workflow(Request $request, Project $project, WorkflowConfiguration $workflow): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['workflow' => ['required', 'string', 'max:50000']]);
        $workflow->apply($project, $data['workflow'], $request->user()->id);

        return back()->with('success', __('Workflow applied atomically.'));
    }

    public function deploymentSchedule(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'scheduled_deployments');
        $data = $this->scheduleData($request);
        $environment->deploymentSchedules()->create([...$data, 'created_by' => $request->user()->id, 'is_enabled' => true]);

        return back()->with('success', __('Deployment schedule created.'));
    }

    public function destroyDeploymentSchedule(Request $request, DeploymentSchedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule->environment);
        $schedule->delete();

        return back()->with('success', __('Deployment schedule deleted.'));
    }

    public function scalingSchedule(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'scheduled_scaling');
        $data = $this->scheduleData($request) + $request->validate(['replicas' => ['required', 'integer', 'gte:'.$environment->minimum_replicas, 'lte:'.$environment->maximum_replicas]]);
        $environment->scalingSchedules()->create([...$data, 'created_by' => $request->user()->id, 'is_enabled' => true]);

        return back()->with('success', __('Scaling schedule created.'));
    }

    public function destroyScalingSchedule(Request $request, ScalingSchedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule->environment);
        $schedule->delete();

        return back()->with('success', __('Scaling schedule deleted.'));
    }

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

    public function scheduledTask(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'scheduled_deployments');
        $data = $this->scheduleData($request) + $request->validate([
            'command' => ['required', 'string', 'max:4000'],
            'timeout_seconds' => ['required', 'integer', 'between:10,3600'],
            'without_overlapping' => ['required', 'boolean'],
            'alert_on_failure' => ['required', 'boolean'],
        ]);
        $environment->scheduledTasks()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'is_enabled' => true,
        ]);

        return back()->with('success', __('Scheduled task created.'));
    }

    public function runScheduledTask(ScheduledTask $task): RedirectResponse
    {
        $this->authorize('update', $task->environment);
        $this->entitlements->enforce($task->environment->project->organization, 'scheduled_deployments');
        if ($task->without_overlapping && $task->runs()->whereIn('status', ['queued', 'running'])->exists()) {
            return back()->withErrors(['task' => __('This task is already running.')]);
        }
        $run = $task->runs()->create(['status' => 'queued']);
        $task->update(['last_queued_at' => now()]);
        RunScheduledTaskJob::dispatch($run->id);

        return back()->with('success', __('Task queued.'));
    }

    public function destroyScheduledTask(ScheduledTask $task): RedirectResponse
    {
        $this->authorize('update', $task->environment);
        $task->delete();

        return back()->with('success', __('Scheduled task deleted.'));
    }

    public function scheduledTaskOutput(ScheduledTaskRun $run)
    {
        $this->authorize('view', $run->task->environment);

        return response($run->output ?: __('No output was recorded.'), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

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

    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->findOrFail($token)->delete();

        return back()->with('success', __('API token revoked.'));
    }

    public function rotateToken(Request $request, int $token): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->owner->is($request->user()), 403);
        $this->entitlements->enforce($organization, 'api');
        $current = $request->user()->tokens()->findOrFail($token);
        $replacement = $request->user()->createToken($current->name, $current->abilities, now()->addYear());
        $current->delete();

        return back()->with('success', __('API token rotated. The previous token has been revoked.'))
            ->with('plainTextToken', $replacement->plainTextToken);
    }

    private function scheduleData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'cron_expression' => ['required', 'string', 'max:100', fn ($attribute, $value, $fail) => CronExpression::isValidExpression($value) ?: $fail(__('Enter a valid five-part cron expression.'))],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
        ]);
    }
}
