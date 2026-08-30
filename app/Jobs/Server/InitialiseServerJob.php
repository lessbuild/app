<?php

namespace App\Jobs\Server;

use App\Actions\Server\UpdateServerIpAction;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InitialiseServerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The server instance.
     *
     * @var \App\Models\Server
     */
    public Server $server;

    public int $tries = 10;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Server  $server
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Execute the job.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        $this->server->update([
            'provisioning_status' => Server::STATUS_WAITING_FOR_IP,
            'provisioning_error' => null,
        ]);

        if (is_null($this->server->public_ip)) {
            (new UpdateServerIpAction($this->server))->handle();
        }

        $this->server->update(['provisioning_status' => Server::STATUS_PROVISIONING]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->server->update([
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}
