<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\ProvisioningScriptRenderer;
use App\Services\Infrastructure\RemoteScriptRunner;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Uploads the unfinished provisioning stages to an imported server, or one whose remote provisioning failed, and starts them. */
final class RunServerProvisioning implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Starting the provisioning script can fail on a newly booted server, so it gets three tries.
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     */
    public int $backoff = 10;

    /**
     * Starts the provisioning script on a server that has its IP and host key.
     *
     * @param  int  $serverId  The server.
     * @param  string  $attempt  The provisioning attempt's token. Retrying provisioning issues a new token, so a job from an earlier attempt does nothing.
     */
    public function __construct(public readonly int $serverId, public readonly string $attempt) {}

    /**
     * Claims the attempt, hands the one-time root password to the script and starts it in the background. The stored
     * password is cleared once the script has it; on failure the attempt is put back so the retry starts cleanly.
     */
    public function handle(RemoteScriptRunner $runner, ProvisioningScriptRenderer $renderer, ServerProvisioningPlan $plan): void
    {
        if ($this->attempt()->where('provisioning_status', Server::STATUS_QUEUED)->whereNull('provisioning_process_id')
            ->update(['provisioning_status' => Server::STATUS_PROVISIONING, 'provisioning_error' => null, 'provisioning_failure_phase' => null]) === 0) {
            return;
        }
        $server = $this->attempt()->firstOrFail();
        if ($server->password !== null) {
            $server->setProvisioningRootPassword($server->password);
        }
        try {
            $process = $runner->start($server, $renderer->remainingServer($server, $plan), "server-{$server->id}-provisioning");
        } catch (Throwable $exception) {
            $this->attempt()->where('provisioning_status', Server::STATUS_PROVISIONING)->whereNull('provisioning_process_id')->update(['provisioning_status' => Server::STATUS_QUEUED]);

            throw $exception;
        }
        $this->attempt()->where('provisioning_status', Server::STATUS_PROVISIONING)
            ->update(['password' => null, 'provisioning_process_id' => $process['id'], 'provisioning_process_path' => $process['path']]);
    }

    /**
     * Marks provisioning failed at the remote phase and clears the password.
     */
    public function failed(Throwable $exception): void
    {
        $this->attempt()->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_PROVISIONING])->update([
            'password' => null, 'provisioning_status' => Server::STATUS_FAILED, 'provisioning_error' => Str::limit($exception->getMessage(), 2000),
            'provisioning_failure_phase' => Server::FAILURE_REMOTE, 'provisioning_process_id' => null, 'provisioning_process_path' => null,
        ]);
    }

    /**
     * The server, only while it's still on this provisioning attempt.
     *
     * @return Builder<Server>
     */
    private function attempt(): Builder
    {
        return Server::query()->whereKey($this->serverId)->where('provisioning_token', $this->attempt);
    }
}
