<?php

namespace App\Console\Commands;

use App\Jobs\Server\CollectServerMetricsJob;
use App\Models\Server;
use Illuminate\Console\Command;

class CollectServerMetricsCommand extends Command
{
    protected $signature = 'buildpusher:servers:metrics';

    protected $description = 'Queue resource metric collection for every active server';

    public function handle(): int
    {
        $count = 0;
        Server::query()
            ->where('provisioning_status', Server::STATUS_ACTIVE)
            ->orderBy('id')
            ->eachById(function (Server $server) use (&$count): void {
                CollectServerMetricsJob::dispatch($server->id);
                $count++;
            });
        $this->info("Queued metric collection for {$count} server(s).");

        return self::SUCCESS;
    }
}
