<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Services\Infrastructure\ServerLogs;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

final class FetchServerLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $serverId, public readonly string $type) {}

    public function handle(ServerLogs $logs): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null || ! array_key_exists($this->type, ServerLogs::TYPES)) {
            return;
        }
        $snapshot = $server->logSnapshots()->firstOrCreate(['type' => $this->type], ['status' => ServerLogSnapshot::STATUS_QUEUED]);
        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            $snapshot->update(['status' => ServerLogSnapshot::STATUS_FAILED, 'error' => 'Logs are only available for active servers.']);

            return;
        }
        $snapshot->update(['status' => ServerLogSnapshot::STATUS_REFRESHING, 'error' => null]);
        $snapshot->update(['status' => ServerLogSnapshot::STATUS_READY, 'log' => $logs->read($server, $this->type), 'error' => null, 'refreshed_at' => CarbonImmutable::now('UTC')]);
    }

    public function failed(Throwable $exception): void
    {
        ServerLogSnapshot::query()->where('server_id', $this->serverId)->where('type', $this->type)
            ->update(['status' => ServerLogSnapshot::STATUS_FAILED, 'error' => Str::limit($exception->getMessage(), 2000)]);
    }
}
