<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTask;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RunScheduledTasksCommand extends Command
{
    protected $signature = 'buildpusher:tasks:scheduled';

    protected $description = 'Queue due application tasks';

    /**
     * Claim each due enabled task once per minute, persist its queued run, and dispatch remote execution.
     *
     * @return int SUCCESS after evaluating task schedules.
     */
    public function handle(): int
    {
        ScheduledTask::query()->where('is_enabled', true)->with('environment.website')->each(function (ScheduledTask $task): void {
            $now = now($task->timezone);
            if (! $task->environment->website
                || ! CronExpression::isValidExpression($task->cron_expression)
                || ! (new CronExpression($task->cron_expression))->isDue($now)
                || $task->last_queued_at?->gte(now()->startOfMinute())) {
                return;
            }
            $claimed = ScheduledTask::query()->whereKey($task->id)
                ->where(fn ($query) => $query->whereNull('last_queued_at')->orWhere('last_queued_at', '<', now()->startOfMinute()))
                ->update(['last_queued_at' => now()]);
            if ($claimed) {
                $run = $task->runs()->create(['status' => 'queued']);
                RunScheduledTaskJob::dispatch($run->id);
            }
        });

        return self::SUCCESS;
    }
}
