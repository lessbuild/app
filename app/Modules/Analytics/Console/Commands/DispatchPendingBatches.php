<?php

namespace App\Modules\Analytics\Console\Commands;

use App\Modules\Analytics\Jobs\ProcessEventBatch;
use App\Modules\Analytics\Models\IngestionBatch;
use Illuminate\Console\Command;

class DispatchPendingBatches extends Command
{
    protected $signature = 'analytics:dispatch-pending {--limit=100}';

    protected $description = 'Dispatch accepted ingestion batches that are still waiting for a worker';

    public function handle(): int
    {
        $count = 0;
        IngestionBatch::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('accepted_at', '<', now()->subSeconds(10))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                ProcessEventBatch::dispatch($id);
                $count++;
            });

        $this->info("Dispatched {$count} pending batches.");

        return self::SUCCESS;
    }
}
