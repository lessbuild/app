<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\ScalingSchedule;
use App\Modules\Deployer\Services\Entitlements;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RunScalingSchedulesCommand extends Command
{
    protected $signature = 'buildpusher:scaling:scheduled';

    protected $description = 'Apply due environment scaling schedules';

    /**
     * Claim due entitled scaling schedules, clamp desired replicas, clear hibernation, and queue application of the runtime state.
     *
     * @param  Entitlements  $entitlements  Workspace entitlement evaluator for the requested automation capability.
     * @return int SUCCESS after evaluating schedules and queuing accepted changes.
     */
    public function handle(Entitlements $entitlements): int
    {
        ScalingSchedule::query()->where('is_enabled', true)->with('environment.project.organization.owner')->each(function (ScalingSchedule $schedule) use ($entitlements): void {
            $environment = $schedule->environment;
            if (! CronExpression::isValidExpression($schedule->cron_expression)
                || ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', (string) $environment->project->organization_id)->exists()
                || ! (new CronExpression($schedule->cron_expression))->isDue(now($schedule->timezone))
                || $schedule->last_run_at?->gte(now()->startOfMinute())
                || ! $entitlements->allows($environment->project->organization, 'scheduled_scaling')) {
                return;
            }
            $replicas = max($environment->minimum_replicas, min($environment->maximum_replicas, $schedule->replicas));
            $claimed = ScalingSchedule::query()->whereKey($schedule->id)
                ->where(fn ($query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<', now()->startOfMinute()))
                ->update(['last_run_at' => now()]);
            if ($claimed) {
                $environment->update(['desired_replicas' => $replicas, 'hibernated_at' => null]);
                ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, false);
            }
        });

        return self::SUCCESS;
    }
}
