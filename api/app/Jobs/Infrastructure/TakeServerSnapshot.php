<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\ServerSnapshots;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class TakeServerSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new TakeServerSnapshot instance.
     *
     * @param  int  $serverId  The server.
     */
    public function __construct(public readonly int $serverId) {}

    /**
     * Take a snapshot now, whatever the server's setting.
     *
     * @param  ServerSnapshots  $snapshots
     * @return void
     */
    public function handle(ServerSnapshots $snapshots): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server !== null) {
            $snapshots->before($server, __('Taken by hand'), force: true);
        }
    }
}
