<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\ServerMetricsCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class CollectServerMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds during which another collection for the same server isn't queued, so a slow server can't pile them up.
     *
     * @var int
     */
    public int $uniqueFor = 240;

    /**
     * Create a new CollectServerMetrics instance.
     *
     * Samples an active server's load, CPU, memory, disk, network and process counts.
     *
     * @param  int  $serverId  The server.
     */
    public function __construct(public readonly int $serverId) {}

    /**
     * Get the job's unique ID, so there's one collection per server at a time.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    /**
     * Collect a sample if the server is still active.
     *
     * @param  ServerMetricsCollector  $collector
     * @return void
     */
    public function handle(ServerMetricsCollector $collector): void
    {
        $server = Server::query()->whereKey($this->serverId)->where('provisioning_status', Server::STATUS_ACTIVE)->first();
        if ($server !== null) {
            $collector->collect($server);
        }
    }
}
