<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\RemoteScriptRunner;
use App\Services\Infrastructure\ReplicationScripts;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Prepares the primary for a new read replica, then starts the replica's copy in the background. The replica check
 * (`databases:check-replicas`) follows the copy and marks the replica streaming once it's following the primary.
 */
final class SetUpReadReplica implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt; the replica shows the error and can be set up again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Room for the primary's restart and starting the copy.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new SetUpReadReplica instance.
     *
     * @param  int  $replicaId  The database server becoming a replica.
     */
    public function __construct(public readonly int $replicaId) {}

    /**
     * Run the primary's part, then start the replica's copy with the primary's application logins.
     *
     * @param  ServerShell  $shell
     * @param  RemoteScriptRunner  $runner
     * @param  ReplicationScripts  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, RemoteScriptRunner $runner, ReplicationScripts $scripts): void
    {
        $replica = Server::query()->with('replicaOf')->find($this->replicaId);
        $primary = $replica?->replicaOf;
        if ($replica === null || $primary === null || $replica->replication_status !== 'setting_up') {
            return;
        }

        $prepared = $shell->run($primary, $scripts->preparePrimary($primary, $replica));
        if (! $prepared->successful()) {
            $this->markFailed($replica, __('Preparing :primary failed: :error', ['primary' => $primary->name, 'error' => mb_substr(trim($prepared->errorOutput ?: $prepared->output), -400)]));

            return;
        }
        $logins = preg_match('/^logins=(\S*)$/m', $prepared->output, $match) === 1 ? $match[1] : '';
        $runner->start($replica, $scripts->startReplica($replica, $primary, $logins), 'read-replica-'.$replica->id);
    }

    /**
     * Record an unexpected failure on the replica.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $replica = Server::query()->find($this->replicaId);
        if ($replica !== null) {
            $this->markFailed($replica, $exception->getMessage());
        }
    }

    /**
     * Mark the replica's setup failed with a reason.
     *
     * @param  Server  $replica
     * @param  string  $error
     * @return void
     */
    private function markFailed(Server $replica, string $error): void
    {
        $replica->forceFill(['replication_status' => 'failed', 'replication_error' => mb_substr($error, 0, 1000), 'replication_checked_at' => now()])->save();
    }
}
