<?php

namespace App\Modules\Analytics\Console\Commands;

use App\Modules\Analytics\Models\SiteDeletionOperation;
use App\Modules\Analytics\Services\Deletion\AnalyticsSiteDeletionService;
use Illuminate\Console\Command;

final class ProcessSiteDeletions extends Command
{
    protected $signature = 'analytics:process-site-deletions {--limit=100}';

    protected $description = 'Retry accepted Analytics site deletion cleanup operations.';

    public function handle(AnalyticsSiteDeletionService $deletions): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $operations = SiteDeletionOperation::query()->whereIn('status', ['fencing', 'waiting', 'blocked'])
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get(['id']);
        $completed = 0;
        $pending = 0;

        foreach ($operations as $operation) {
            $result = $deletions->processAccepted((string) $operation->getKey());
            $result->completed() ? $completed++ : $pending++;
        }

        $this->info("Completed {$completed} accepted site deletion(s); {$pending} remain pending or blocked.");

        return self::SUCCESS;
    }
}
