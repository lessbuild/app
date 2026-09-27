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

    public int $uniqueFor = 240;

    public function __construct(public readonly int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(ServerMetricsCollector $collector): void
    {
        $server = Server::query()->whereKey($this->serverId)->where('provisioning_status', Server::STATUS_ACTIVE)->first();
        if ($server !== null) {
            $collector->collect($server);
        }
    }
}
