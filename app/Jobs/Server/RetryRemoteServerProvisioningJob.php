<?php

namespace App\Jobs\Server;

use App\Actions\Server\RetryRemoteServerProvisioningAction;
use App\Models\Server;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RetryRemoteServerProvisioningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly int $serverId,
        public readonly string $attemptToken,
    ) {}

    public function handle(Runner $runner): void
    {
        $started = $this->attemptQuery()
            ->where('provisioning_status', Server::STATUS_QUEUED)
            ->whereNull('provisioning_process_id')
            ->update([
                'provisioning_status' => Server::STATUS_PROVISIONING,
                'provisioning_error' => null,
                'provisioning_failure_phase' => null,
            ]);
        if ($started === 0) {
            return;
        }

        $server = $this->attemptQuery()->firstOrFail();
        if ($server->password) {
            $server->setProvisioningRootPassword($server->password);
        }

        try {
            $process = (new RetryRemoteServerProvisioningAction($server, $runner))->handle();
        } catch (Throwable $exception) {
            $this->attemptQuery()
                ->where('provisioning_status', Server::STATUS_PROVISIONING)
                ->whereNull('provisioning_process_id')
                ->update(['provisioning_status' => Server::STATUS_QUEUED]);

            throw $exception;
        }

        $this->attemptQuery()
            ->where('provisioning_status', Server::STATUS_PROVISIONING)
            ->update([
                'password' => null,
                'provisioning_process_id' => $process['id'],
                'provisioning_process_path' => $process['path'],
            ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->attemptQuery()
            ->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_PROVISIONING])
            ->update([
                'password' => null,
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => str($exception->getMessage())->limit(2000),
                'provisioning_failure_phase' => Server::FAILURE_REMOTE,
                'provisioning_process_id' => null,
                'provisioning_process_path' => null,
            ]);
    }

    private function attemptQuery(): Builder
    {
        return Server::query()
            ->whereKey($this->serverId)
            ->where('provisioning_token', $this->attemptToken);
    }
}
