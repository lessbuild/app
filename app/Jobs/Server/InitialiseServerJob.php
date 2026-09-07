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
     * Capture the server and current initialization token so later retries cannot be overwritten.
     *
     * Create a new job instance.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->attemptToken = $server->initialization_token;
    }

    /**
     * Claim the matching initialization attempt, discover its address when absent, and advance it to remote provisioning; stale attempts return without changing state.
     *
     * Execute the job.
     *
     * @param  UpdateServerIpAction  $updateServerIp  Action that resolves cloud IP addresses and pins their SSH host identity.
     * @return void
     *
     * @throws \Exception
     */
    public function handle(UpdateServerIpAction $updateServerIp): void
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
                'initialization_token' => null,
            ]);
    }

    /**
     * Mark only the captured initialization attempt failed and clear its token while retaining the initialization failure phase.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        $this->attemptQuery()
            ->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])
            ->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => str($exception->getMessage())->limit(2000),
                'provisioning_failure_phase' => Server::FAILURE_INITIALIZATION,
                'initialization_token' => null,
            ]);
    }

    /**
     * Constrain server updates to the captured initialization attempt, including legacy null tokens.
     *
     * @return Builder<Server> An unexecuted query restricted to this server and the attempt owned by this job.
     */
    private function attemptQuery(): Builder
    {
        $query = Server::query()->whereKey($this->server->id);

        return $this->attemptToken === null
            ? $query->whereNull('initialization_token')
            : $query->where('initialization_token', $this->attemptToken);
    }
}
