<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Jobs\Deploy\RunScheduledTask;
use App\Models\DeploymentSchedule;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Runs what's due each minute: scheduled deploys, scaling schedules and scheduled tasks, each claimed once per minute
 * and only on a plan that still includes it.
 */
final class Automation
{
    /**
     * Create a new Automation instance.
     *
     * Runs due schedules and queues task runs.
     *
     * @param  Deployments  $deployments  Queues scheduled deploys.
     * @param  Entitlements  $entitlements  Checks the plan still includes each kind of schedule.
     */
    public function __construct(private readonly Deployments $deployments, private readonly Entitlements $entitlements) {}

    /**
     * Run every enabled schedule and task that's due in this minute.
     *
     * @param  CarbonInterface  $now
     * @return array{deploys: int, scaling: int, tasks: int} how many of each ran
     */
    public function runDue(CarbonInterface $now): array
    {
        $ran = ['deploys' => 0, 'scaling' => 0, 'tasks' => 0];
        DeploymentSchedule::query()->where('is_enabled', true)->with(['environment.project.account', 'creator'])->each(function (DeploymentSchedule $schedule) use ($now, &$ran): void {
            if ($schedule->isDue($now) && $this->entitlements->for($schedule->environment->project->account)->has('deploy.scheduled') && $schedule->claim($now)) {
                $schedule->forceFill(['last_result' => $this->deploy($schedule)])->save();
                $ran['deploys']++;
            }
        });
        ScalingSchedule::query()->where('is_enabled', true)->with('environment.project.account')->each(function (ScalingSchedule $schedule) use ($now, &$ran): void {
            if ($schedule->isDue($now) && $this->entitlements->for($schedule->environment->project->account)->has('deploy.scaling') && $schedule->claim($now)) {
                $schedule->forceFill(['last_result' => $this->scale($schedule)])->save();
                $ran['scaling']++;
            }
        });
        ScheduledTask::query()->where('is_enabled', true)->with('environment.project.account')->each(function (ScheduledTask $task) use ($now, &$ran): void {
            if ($task->isDue($now) && $this->entitlements->for($task->environment->project->account)->has('deploy.scheduled') && $task->claim($now) && $this->queue($task) !== null) {
                $ran['tasks']++;
            }
        });

        return $ran;
    }

    /**
     * Queue a run of a task, unless it skips overlapping runs and one hasn't finished.
     *
     * @param  ScheduledTask  $task
     * @param  User|null  $requester  who ran it by hand; null on schedule
     * @return ScheduledTaskRun|null
     */
    public function queue(ScheduledTask $task, ?User $requester = null): ?ScheduledTaskRun
    {
        return DB::transaction(function () use ($task, $requester): ?ScheduledTaskRun {
            $locked = ScheduledTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->without_overlapping && $locked->runs()->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }
            $run = new ScheduledTaskRun;
            $run->forceFill(['scheduled_task_id' => $locked->id, 'requested_by' => $requester?->id, 'status' => 'queued'])->save();
            $locked->forceFill(['last_queued_at' => now()])->save();
            RunScheduledTask::dispatch($run->id)->afterCommit();

            return $run;
        });
    }

    /**
     * Deploy each repository connected to the schedule's environment, as its creator, and say what happened to each.
     *
     * @param  DeploymentSchedule  $schedule
     * @return string
     */
    private function deploy(DeploymentSchedule $schedule): string
    {
        $repositories = $schedule->environment->repositories()->with(['environment', 'provider', 'website.server'])->orderBy('name')->get();
        if ($repositories->isEmpty()) {
            return __('No repository deploys this environment.');
        }
        $results = [];
        foreach ($repositories as $repository) {
            $blocked = $this->deployments->blockReason($repository);
            $build = match (true) {
                $blocked !== null, ! $repository->isDeploymentReady() => null,
                default => $this->deployments->queue($repository, ['trigger_source' => 'scheduled'], $schedule->creator),
            };
            $results[] = $repository->name.': '.match (true) {
                $blocked !== null => $blocked,
                ! $repository->isDeploymentReady() => __('not ready'),
                $build === null => __('a deploy was already running'),
                default => __('deploy #:id', ['id' => $build->id]),
            };
        }

        return mb_substr(implode('; ', $results), 0, 255);
    }

    /**
     * Set the environment's running replicas from the schedule, within its minimum and maximum, and apply them now.
     *
     * @param  ScalingSchedule  $schedule
     * @return string
     */
    private function scale(ScalingSchedule $schedule): string
    {
        $environment = $schedule->environment;
        $replicas = max($environment->minimum_replicas, min($environment->maximum_replicas, $schedule->replicas));
        $environment->forceFill(['desired_replicas' => $replicas])->save();
        ApplyEnvironmentRuntime::dispatch($environment->id, false);

        return trans_choice('Scaled to :count replica|Scaled to :count replicas', $replicas);
    }
}
