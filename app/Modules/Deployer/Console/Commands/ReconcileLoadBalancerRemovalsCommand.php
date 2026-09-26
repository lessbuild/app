<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Actions\LoadBalancer\QueueLoadBalancerRemovalAction;
use App\Modules\Deployer\Models\LoadBalancer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReconcileLoadBalancerRemovalsCommand extends Command
{
    protected $signature = 'buildpusher:load-balancers:reconcile-removals {--limit=100 : Maximum stale removals to requeue (1-500)}';

    protected $description = 'Requeue stale Deployer load-balancer removals';

    /** Re-dispatch visible removals whose queue work did not start or remain durable. */
    public function handle(QueueLoadBalancerRemovalAction $queueRemoval): int
    {
        if (! Schema::connection('deployer')->hasTable('load_balancers')) {
            $this->info('No Deployer load-balancer table is available; no removals were reconciled.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);

        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::FAILURE;
        }

        $staleBefore = now()->subMinutes(10);
        $staleRemovals = LoadBalancer::query()
            ->where('status', 'removing')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'server_id']);

        $dispatchAttempts = 0;
        $failed = 0;

        foreach ($staleRemovals as $loadBalancer) {
            try {
                $queueRemoval->handle((int) $loadBalancer->server_id, (int) $loadBalancer->id);
                $dispatchAttempts++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->info(sprintf(
            'Load-balancer removal reconciliation: checked %d, dispatch attempts %d, failed %d.',
            $staleRemovals->count(),
            $dispatchAttempts,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
