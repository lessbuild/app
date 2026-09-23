<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Jobs\Repository\ObserveDeploymentJob;
use App\Modules\Deployer\Models\DeploymentObservation;
use Illuminate\Console\Command;

class ProcessDeploymentObservationsCommand extends Command
{
    protected $signature = 'buildpusher:deployments:observe';

    protected $description = 'Queue due post-deployment observations';

    /**
     * Queue a bounded batch of due observations; each job rechecks its lease and identity before probing.
     */
    public function handle(): int
    {
        $now = now();
        $ids = DeploymentObservation::query()
            ->whereIn('status', DeploymentObservation::ACTIVE_STATUSES)
            ->where(function ($query) use ($now): void {
                $query->where('next_check_at', '<=', $now)
                    ->orWhere(function ($query) use ($now): void {
                        $query->where('status', DeploymentObservation::STATUS_OBSERVING)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $now);
                    });
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        $ids->each(fn (int $id): mixed => ObserveDeploymentJob::dispatch($id));

        $this->info("Queued {$ids->count()} deployment observation(s).");

        return self::SUCCESS;
    }
}
