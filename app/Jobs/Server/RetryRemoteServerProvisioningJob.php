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

    /**
     * Capture the server and attempt token that authorize this remote provisioning retry.
     *
     * @param  int  $serverId  Managed server identifier retained for remote work when the job runs.
     * @param  string  $attemptToken  Provisioning generation token restricting this job to the retry that queued it.
     */
    public function __construct(
        public readonly int $serverId,
        public readonly string $attemptToken,
    ) {}

    /**
     * Claim the matching queued attempt, launch its remaining setup, and save its process identity; reset the claim and rethrow startup failures so the queue can retry.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
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

    /**
     * Mark only the matching active retry failed, remove its transient root credential, and clear remote process metadata.
     *
     * @param  Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
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

    /**
     * Constrain server reads and updates to the captured remote provisioning token.
     *
     * @return Builder<Server> An unexecuted query restricted to this server and the attempt owned by this job.
     */
    private function attemptQuery(): Builder
    {
        return Server::query()
            ->whereKey($this->serverId)
            ->where('provisioning_token', $this->attemptToken);
    }
}
