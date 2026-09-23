<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class AlertDeliveryQueue
{
    public function __construct(private readonly LockIncident $incidents) {}

    public const NAME = 'alerts';

    public const TIMEOUT = 45;

    public const LEASE_SECONDS = 120;

    public const MAX_ATTEMPTS = 5;

    public const BACKOFF = [30, 120, 600, 1800];

    public function dispatch(AlertDelivery $delivery): void
    {
        $config = config('queue.connections.'.self::NAME, []);
        if (DB::connection('monitor')->transactionLevel() === 0 || ($config['driver'] ?? null) !== 'database'
            || ($config['connection'] ?? null) !== 'monitor'
            || ($config['table'] ?? null) !== 'jobs' || ($config['retry_after'] ?? 0) < self::LEASE_SECONDS) {
            throw new LogicException('Alerts require a primary database queue and an open outbox transaction.');
        }
        $job = (new ProcessAlertDelivery($delivery->id, $delivery->generation))
            ->onConnection(self::NAME)->onQueue(self::NAME)->beforeCommit();
        $queue = Queue::connection(self::NAME);
        $jobId = $delivery->next_attempt_at?->isFuture()
            ? $queue->later($delivery->next_attempt_at, $job, '', self::NAME)
            : $queue->push($job, '', self::NAME);
        $uuid = DB::connection('monitor')->table('jobs')->where('id', $jobId)->value('telemetry_uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new LogicException('An alert job must have a durable identifier.');
        }
        $delivery->forceFill(['queue_job_uuid' => $uuid])->save();
    }

    /** All delivery mutations use workspace → application → environment → rule → incident → destination → delivery. */
    public function lock(string $id): ?AlertDelivery
    {
        $hint = AlertDelivery::query()->find($id);
        if ($hint === null) {
            return null;
        }
        $workspace = Workspace::query()->lockForUpdate()->find($hint->workspace_id);
        $incident = $hint->incident_id === null ? null : $this->incidents->find($hint->incident_id);
        $destination = AlertDestination::withTrashed()->where('workspace_id', $hint->workspace_id)->lockForUpdate()->find($hint->alert_destination_id);
        $destination?->setRelation('workspace', $workspace);
        $delivery = AlertDelivery::query()->lockForUpdate()->find($id);
        $delivery?->setRelation('destination', $destination)->setRelation('incident', $incident)->setRelation('workspace', $workspace);

        return $delivery;
    }

    public function jobExists(AlertDelivery $delivery): bool
    {
        return $delivery->queue_job_uuid !== null && DB::connection('monitor')->table('jobs')->where('queue', self::NAME)
            ->where('telemetry_uuid', $delivery->queue_job_uuid)->exists();
    }
}
