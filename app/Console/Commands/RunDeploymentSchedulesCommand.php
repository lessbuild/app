<?php

namespace App\Console\Commands;

use App\Models\Build;
use App\Models\DeploymentSchedule;
use App\Services\DeploymentLauncher;
use App\Services\Entitlements;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RunDeploymentSchedulesCommand extends Command
{
    protected $signature = 'buildpusher:deployments:scheduled';

    protected $description = 'Queue deployments whose workspace schedules are due';

    /**
     * Claim due deployment schedules once per minute and request matching environment repository deployments when entitlements allow.
     *
     * @param  DeploymentLauncher  $launcher  Service that authorizes and queues scheduled deployment requests.
     * @param  Entitlements  $entitlements  Workspace entitlement evaluator for the requested automation capability.
     * @return int SUCCESS after evaluating schedules; accepted deployments execute asynchronously.
     */
    public function handle(DeploymentLauncher $launcher, Entitlements $entitlements): int
    {
        DeploymentSchedule::query()->where('is_enabled', true)->with(['environment.project.organization.owner', 'environment.website.repositories', 'creator'])
            ->each(function (DeploymentSchedule $schedule) use ($launcher, $entitlements): void {
                $now = now($schedule->timezone);
                if (! CronExpression::isValidExpression($schedule->cron_expression)
                    || ! (new CronExpression($schedule->cron_expression))->isDue($now)
                    || $schedule->last_run_at?->gte(now()->startOfMinute())
                    || ! $entitlements->allows($schedule->environment->project->organization, 'scheduled_deployments')) {
                    return;
                }
                $claimed = DeploymentSchedule::query()->whereKey($schedule->id)
                    ->where(fn ($query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<', now()->startOfMinute()))
                    ->update(['last_run_at' => now()]);
                if (! $claimed) {
                    return;
                }
                $repository = $schedule->environment->website?->repositories
                    ->firstWhere('branch', $schedule->environment->branch)
                    ?? $schedule->environment->website?->repositories->first();
                if ($repository) {
                    $launcher->launch($repository, $schedule->creator, Build::TRIGGER_SCHEDULED);
                }
            });

        return self::SUCCESS;
    }
}
