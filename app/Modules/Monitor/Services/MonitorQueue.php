<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class MonitorQueue
{
    public const NAME = 'checks';

    public const TIMEOUT = 45;

    public const LEASE_SECONDS = 120;

    public function dispatch(MonitorCheck $check): void
    {
        $config = config('queue.connections.'.self::NAME, []);
        if (DB::connection('monitor')->transactionLevel() === 0 || ($config['driver'] ?? null) !== 'database'
            || ($config['connection'] ?? null) !== 'monitor'
            || ($config['table'] ?? null) !== 'jobs' || ($config['retry_after'] ?? 0) < self::LEASE_SECONDS) {
            throw new LogicException('Checks require a primary database queue and an open scheduling transaction.');
        }
        $job = (new ProcessMonitorCheck($check->id))->onConnection(self::NAME)->onQueue(self::NAME)->beforeCommit();
        $jobId = Queue::connection(self::NAME)->push($job, '', self::NAME);
        $uuid = DB::connection('monitor')->table('jobs')->where('id', $jobId)->value('telemetry_uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new LogicException('A monitor job must have a durable identifier.');
        }
        $check->forceFill(['queue_job_uuid' => $uuid])->save();
    }

    /** Application → environment → monitor; callers then lock check / incident rows. */
    public function lockMonitor(int $id): ?Monitor
    {
        $monitor = Monitor::withTrashed()->find($id);
        $environment = $monitor === null ? null : Environment::withTrashed()->find($monitor->environment_id);
        if ($environment === null) {
            return null;
        }
        $application = Application::withTrashed()->lockForUpdate()->find($environment->application_id);
        $environment = Environment::withTrashed()->lockForUpdate()->find($environment->id);
        $monitor = Monitor::withTrashed()->lockForUpdate()->find($id);
        $environment?->setRelation('application', $application);
        $monitor?->setRelation('environment', $environment);

        return $monitor;
    }

    public function eligible(?Monitor $monitor): bool
    {
        return $monitor !== null && ! $monitor->trashed() && $monitor->enabled
            && $monitor->environment !== null && ! $monitor->environment->trashed()
            && $monitor->environment->status === 'active'
            && $monitor->environment->application !== null && ! $monitor->environment->application->trashed();
    }

    public function discardPendingJob(MonitorCheck $check): void
    {
        if ($check->queue_job_uuid !== null) {
            DB::connection('monitor')->table('jobs')->where('queue', self::NAME)->where('telemetry_uuid', $check->queue_job_uuid)
                ->whereNull('reserved_at')->delete();
        }
    }
}
