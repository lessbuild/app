<?php

namespace App\Jobs;

use App\Models\ScheduledTaskRun;
use App\Services\IncidentNotifier;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunScheduledTaskJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        $run = ScheduledTaskRun::query()->with('task')->find($this->runId);

        return $run?->task?->without_overlapping ? 'task-'.$run->scheduled_task_id : 'run-'.$this->runId;
    }

    public function handle(Runner $runner, IncidentNotifier $notifier): void
    {
        $run = ScheduledTaskRun::query()->with(['task.environment.website.server', 'task.environment.project.organization.owner'])->find($this->runId);
        $task = $run?->task;
        $website = $task?->environment?->website;
        if (! $run || ! $task || ! $website?->server) {
            return;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);
        $started = hrtime(true);
        $command = escapeshellarg(base64_encode($task->command));
        $root = escapeshellarg('/var/www/'.$website->deployment_slug.'/current');
        $environment = escapeshellarg('/var/www/'.$website->deployment_slug.'/.env');
        $timeout = max(10, min(3600, $task->timeout_seconds));
        $script = <<<BASH
        set -o pipefail
        cd -- {$root}
        set -a
        [ ! -f {$environment} ] || . {$environment}
        set +a
        TASK_COMMAND="$(printf '%s' {$command} | base64 --decode)"
        sudo -u www-data --preserve-env timeout --signal=TERM --kill-after=10 {$timeout} /bin/bash -lc "\$TASK_COMMAND"
        BASH;
        try {
            $result = $runner->server($website->server)->create()->execute($script);
            $successful = $result->isSuccessful();
            $output = trim($result->getOutput().($successful ? '' : "\n".$result->getErrorOutput()));
        } catch (Throwable) {
            $successful = false;
            $output = 'Remote task execution failed before output was available.';
        }
        $output = mb_substr($output, -65535);
        $run->update([
            'status' => $successful ? 'succeeded' : 'failed',
            'output' => $output,
            'finished_at' => now(),
            'duration_ms' => min(4294967295, (int) ((hrtime(true) - $started) / 1_000_000)),
        ]);
        $task->update(['last_finished_at' => now(), 'last_status' => $run->status]);
        $expiredRunIds = $task->runs()->latest()->skip(50)->pluck('id');
        ScheduledTaskRun::query()->whereIn('id', $expiredRunIds)->delete();
        $owner = $task->environment->project->organization->owner;
        if (! $successful && $task->alert_on_failure) {
            $notifier->fail($owner, 'scheduled_task', $task->id, $task->name.' failed', 'A scheduled task failed. Review its encrypted run output in Automation.');
        } elseif ($successful) {
            $notifier->recoverIfOpen($owner, 'scheduled_task', $task->id, $task->name.' recovered', 'The scheduled task completed successfully.');
        }
    }
}
