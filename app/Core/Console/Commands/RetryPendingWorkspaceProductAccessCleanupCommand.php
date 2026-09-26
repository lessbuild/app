<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Workspaces\RetryPendingWorkspaceProductAccessCleanup;
use Illuminate\Console\Command;

final class RetryPendingWorkspaceProductAccessCleanupCommand extends Command
{
    protected $signature = 'workspace-product-access:retry-cleanup {--limit=100 : Maximum pending grants to process (1-1000)} {--apply : Retry safe local-membership cleanup}';

    protected $description = 'Retry product membership cleanup after Core access has already been revoked';

    public function handle(RetryPendingWorkspaceProductAccessCleanup $cleanup): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $pending = $cleanup->pendingCount();
        if (! $this->option('apply')) {
            $this->info("{$pending} revoked product grant(s) have pending local-membership cleanup. Pass --apply to retry up to {$limit}.");

            return self::SUCCESS;
        }

        $results = $cleanup->retry($limit);
        $this->info(sprintf(
            'Processed %d cleanup record(s): %d completed, %d still pending, %d skipped.',
            $results['processed'],
            $results['completed'],
            $results['pending'],
            $results['skipped'],
        ));

        return self::SUCCESS;
    }
}
