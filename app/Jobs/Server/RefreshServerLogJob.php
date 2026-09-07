<?php

namespace App\Jobs\Server;

use App\Actions\Server\CollectServerLogAction;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshServerLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Capture the server and supported log category for asynchronous snapshot refresh.
     *
     * @param  int  $serverId  Managed server identifier retained for remote work when the job runs.
     * @param  string  $type  Supported log category identifying the snapshot and remote log source.
     */
    public function __construct(
        public readonly int $serverId,
        public readonly string $type,
    ) {}

    /**
     * Refresh an allowlisted log snapshot for an active server; skip missing servers or unsupported categories and mark inactive-server snapshots failed.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        $server = Server::find($this->serverId);
        if (! $server || ! in_array($this->type, CollectServerLogAction::TYPES, true)) {
            return;
        }

        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            $server->logSnapshots()
                ->where('type', $this->type)
                ->update([
                    'status' => ServerLogSnapshot::STATUS_FAILED,
                    'error' => 'Logs are only available for active servers.',
                ]);

            return;
        }

        $snapshot = $server->logSnapshots()->firstOrCreate(
            ['type' => $this->type],
            ['status' => ServerLogSnapshot::STATUS_QUEUED],
        );
        $snapshot->update([
            'status' => ServerLogSnapshot::STATUS_REFRESHING,
            'error' => null,
        ]);

        $snapshot->update([
            'status' => ServerLogSnapshot::STATUS_READY,
            'log' => (new CollectServerLogAction($runner))->handle($server, $this->type),
            'error' => null,
            'refreshed_at' => now(),
        ]);
    }

    /**
     * Store a bounded queue failure on the requested server log category.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        ServerLogSnapshot::query()
            ->where('server_id', $this->serverId)
            ->where('type', $this->type)
            ->update([
                'status' => ServerLogSnapshot::STATUS_FAILED,
                'error' => str($exception->getMessage())->limit(2000),
            ]);
    }
}
