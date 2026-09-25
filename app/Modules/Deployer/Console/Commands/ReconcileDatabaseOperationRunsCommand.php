<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Modules\Deployer\Jobs\Database\ManageDatabaseUserJob;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReconcileDatabaseOperationRunsCommand extends Command
{
    protected $signature = 'buildpusher:databases:reconcile-operations {--limit=100 : Maximum stale database operations to requeue (1-500)}';

    protected $description = 'Requeue stale Deployer database operations';

    public function handle(): int
    {
        if (! Schema::connection('deployer')->hasTable('database_operation_runs')) {
            $this->info('No Deployer database operation table is available; no operations were reconciled.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);

        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::FAILURE;
        }

        $queuedBefore = now()->subMinutes(2);
        $now = now();
        $staleRuns = DatabaseOperationRun::query()
            ->whereIn('operation', ['inspection', 'user_apply', 'user_remove'])
            ->where(function ($query) use ($queuedBefore, $now): void {
                $query->where(function ($queued) use ($queuedBefore): void {
                    $queued->where('status', DatabaseOperationRun::QUEUED)
                        ->where('created_at', '<=', $queuedBefore);
                })->orWhere(function ($running) use ($now): void {
                    $running->where('status', DatabaseOperationRun::RUNNING)
                        ->where(function ($lease) use ($now): void {
                            $lease->whereNull('lease_expires_at')
                                ->orWhere('lease_expires_at', '<=', $now);
                        });
                });
            })
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'environment_resource_id', 'operation', 'subject_id']);

        $dispatchAttempts = 0;
        $failed = 0;

        foreach ($staleRuns as $operationRun) {
            if (in_array($operationRun->operation, ['user_apply', 'user_remove'], true)
                && (int) $operationRun->subject_id < 1) {
                $operationRun->markFailed();
                $failed++;

                continue;
            }

            try {
                match ($operationRun->operation) {
                    'inspection' => CollectDatabaseSnapshotJob::dispatch(
                        (int) $operationRun->environment_resource_id,
                        (int) $operationRun->getKey(),
                    ),
                    'user_apply' => ManageDatabaseUserJob::dispatch(
                        (int) $operationRun->subject_id,
                        'apply',
                        (int) $operationRun->getKey(),
                    ),
                    'user_remove' => ManageDatabaseUserJob::dispatch(
                        (int) $operationRun->subject_id,
                        'remove',
                        (int) $operationRun->getKey(),
                    ),
                };

                $dispatchAttempts++;
            } catch (Throwable) {
                $operationRun->markFailed();
                $failed++;
            }
        }

        $this->info(sprintf(
            'Database operation reconciliation: checked %d, dispatch attempts %d, failed %d.',
            $staleRuns->count(),
            $dispatchAttempts,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
