<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Jobs\Monitoring\ProcessAlertDelivery;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class AlertDeliveryQueue
{
    public function __construct(private readonly IncidentLocks $incidents) {}

    public const NAME = 'alerts';

    public const TIMEOUT = 45;

    public const LEASE_SECONDS = 120;

    public const MAX_ATTEMPTS = 5;

    public const BACKOFF = [30, 120, 600, 1800];

    public function dispatch(AlertDelivery $delivery): void
    {
        $config = config('queue.connections.'.self::NAME, []);
        if (DB::transactionLevel() === 0 || ($config['driver'] ?? null) !== 'database'
            || ! in_array($config['connection'] ?? null, [null, DB::getDefaultConnection()], true)
            || ($config['table'] ?? null) !== 'jobs' || ($config['retry_after'] ?? 0) < self::LEASE_SECONDS) {
            throw new LogicException('Alerts require a primary database queue and an open outbox transaction.');
        }
        $job = (new ProcessAlertDelivery($delivery->id, $delivery->generation))
            ->onConnection(self::NAME)->onQueue(self::NAME)->beforeCommit();
        $queue = Queue::connection(self::NAME);
        $jobId = $delivery->next_attempt_at?->isFuture()
            ? $queue->later($delivery->next_attempt_at, $job, '', self::NAME)
            : $queue->push($job, '', self::NAME);
        $uuid = DB::table('jobs')->where('id', $jobId)->value('job_uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new LogicException('An alert job must have a durable identifier.');
        }
        $delivery->forceFill(['queue_job_uuid' => $uuid])->save();
    }

    /** All delivery mutations use account → project → environment → monitor → incident → destination → delivery. */
    public function lock(string $id): ?AlertDelivery
    {
        $hint = AlertDelivery::query()->find($id);
        if ($hint === null) {
            return null;
        }
        $account = Account::query()->lockForUpdate()->find($hint->account_id);
        $incident = $hint->incident_id === null ? null : $this->incidents->find($hint->incident_id);
        $destination = AlertDestination::withTrashed()->where('account_id', $hint->account_id)->lockForUpdate()->find($hint->alert_destination_id);
        $destination?->setRelation('account', $account);
        $delivery = AlertDelivery::query()->lockForUpdate()->find($id);
        $delivery?->setRelation('destination', $destination)->setRelation('incident', $incident)->setRelation('account', $account);

        return $delivery;
    }

    public function jobExists(AlertDelivery $delivery): bool
    {
        return $delivery->queue_job_uuid !== null && DB::table('jobs')->where('queue', self::NAME)
            ->where('job_uuid', $delivery->queue_job_uuid)->exists();
    }
}
