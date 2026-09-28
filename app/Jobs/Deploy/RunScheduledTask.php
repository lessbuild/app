<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Membership;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\Server;
use App\Notifications\ScheduledTaskStatusChanged;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/**
 * Runs one queued run of a scheduled task on its website's server and records the outcome. A task that starts failing,
 * or recovers, tells the members with Deploy access when its alerts are on.
 */
final class RunScheduledTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * A run isn't retried: the command may not be safe to repeat.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Seconds the worker waits: the longest task timeout plus room for the connection.
     *
     * @var int
     */
    public int $timeout = 3700;

    /**
     * Create a new RunScheduledTask instance.
     *
     * Runs one task run.
     *
     * @param  int  $runId  The queued run.
     */
    public function __construct(public readonly int $runId) {}

    /**
     * Claim the queued run, run the command, record its output (the last 64 KB), exit code and duration, prune runs past
     * the history limit, and send alerts on a change between success and failure.
     *
     * @param  ServerShell  $shell
     * @return void
     */
    public function handle(ServerShell $shell): void
    {
        if (ScheduledTaskRun::query()->whereKey($this->runId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]) === 0) {
            return;
        }
        $run = ScheduledTaskRun::query()->with('task.website.server')->findOrFail($this->runId);
        $task = $run->task;
        $started = hrtime(true);
        try {
            $server = $task->website->server;
            if ($server === null || $server->provisioning_status !== Server::STATUS_ACTIVE) {
                throw new RuntimeException('The website’s server isn’t active.');
            }
            $result = $shell->run($server, self::script($task));
            [$succeeded, $output, $exitCode] = [$result->successful(), $result->combined(), $result->exitCode];
        } catch (Throwable $exception) {
            [$succeeded, $output, $exitCode] = [false, $exception->getMessage(), null];
        }
        $previous = $task->last_status;
        $run->forceFill([
            'status' => $succeeded ? 'succeeded' : 'failed', 'output' => mb_substr(trim($output), -65536), 'exit_code' => $exitCode,
            'finished_at' => now(), 'duration_ms' => min(4294967295, intdiv(hrtime(true) - $started, 1_000_000)),
        ])->save();
        $task->forceFill(['last_finished_at' => now(), 'last_status' => $run->status])->save();
        $task->runs()->whereKeyNot($task->runs()->latest('id')->limit(ScheduledTask::KEEP_RUNS)->pluck('id'))->delete();
        if ($task->alert_on_failure && ($succeeded ? $previous === 'failed' : $previous !== 'failed')) {
            $this->alert($task, $run);
        }
    }

    /**
     * Build the command: in the current release, with the website's `.env` exported, as `www-data`, under the task's
     * timeout. The task's command is passed encoded so no quoting can break out.
     *
     * @param  ScheduledTask  $task
     * @return string
     */
    public static function script(ScheduledTask $task): string
    {
        $slug = $task->website->deployment_slug;
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', $slug) !== 1) {
            throw new RuntimeException('The website directory name is invalid.');
        }
        $root = escapeshellarg("/var/www/{$slug}/current");
        $environment = escapeshellarg("/var/www/{$slug}/.env");
        $command = escapeshellarg(base64_encode($task->command));
        $timeout = max(10, min(3600, $task->timeout_seconds));

        return <<<BASH
        set -o pipefail
        cd -- {$root}
        set -a
        [ ! -f {$environment} ] || . {$environment}
        set +a
        TASK_COMMAND="\$(printf '%s' {$command} | base64 --decode)"
        sudo -u www-data --preserve-env timeout --signal=TERM --kill-after=10 {$timeout} /bin/bash -lc "\$TASK_COMMAND" 2>&1
        BASH;
    }

    /**
     * Tell the account's members who may configure the environment that the task started failing or recovered.
     *
     * @param  ScheduledTask  $task
     * @param  ScheduledTaskRun  $run
     * @return void
     */
    private function alert(ScheduledTask $task, ScheduledTaskRun $run): void
    {
        $task->loadMissing('environment.project');
        Membership::query()->where('account_id', $task->environment->project->account_id)->with('user')->get()
            ->filter(fn (Membership $membership): bool => $membership->user->can('configureDeploy', $task->environment))
            ->each(fn (Membership $membership) => $membership->user->notify(new ScheduledTaskStatusChanged($task, $run)));
    }
}
