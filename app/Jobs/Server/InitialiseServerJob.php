<?php

namespace App\Jobs\Server;

use App\Actions\Server\UpdateServerIpAction;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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
     */
    public Server $server;

    public ?string $attemptToken;

    public int $tries = 10;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->attemptToken = $server->provisioning_token;
    }

    /**
     * Execute the job.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle(UpdateServerIpAction $updateServerIp)
    {
        $started = $this->attemptQuery()
            ->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])
            ->update([
                'provisioning_status' => Server::STATUS_WAITING_FOR_IP,
                'provisioning_error' => null,
                'provisioning_failure_phase' => null,
            ]);
        if ($started === 0) {
            return;
        }

        $this->server->refresh();

        if (is_null($this->server->public_ip)) {
            $updateServerIp->handle($this->server);
        }

        $this->attemptQuery()
            ->where('provisioning_status', Server::STATUS_WAITING_FOR_IP)
            ->update([
                'provisioning_status' => Server::STATUS_PROVISIONING,
                'provisioning_failure_phase' => null,
            ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->attemptQuery()
            ->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])
            ->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => str($exception->getMessage())->limit(2000),
                'provisioning_failure_phase' => Server::FAILURE_INITIALIZATION,
            ]);
    }

    private function attemptQuery(): Builder
    {
        $query = Server::query()->whereKey($this->server->id);

        return $this->attemptToken === null
            ? $query->whereNull('provisioning_token')
            : $query->where('provisioning_token', $this->attemptToken);
    }
}
