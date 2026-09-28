<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Jobs\Monitoring\ProcessMonitorCheck;
use App\Models\Environment;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class MonitorQueue
{
    public const NAME = 'checks';

    public const TIMEOUT = 45;

    public const LEASE_SECONDS = 120;

    /**
     * Queues a check's job inside the scheduling transaction on the primary database queue, and remembers the job's UUID
     * so it can be discarded if the check is cancelled.
     *
     * @param  MonitorCheck  $check
     * @return void
     */
    public function dispatch(MonitorCheck $check): void
    {
        $config = config('queue.connections.'.self::NAME, []);
        if (DB::transactionLevel() === 0 || ($config['driver'] ?? null) !== 'database'
            || ! in_array($config['connection'] ?? null, [null, DB::getDefaultConnection()], true)
            || ($config['table'] ?? null) !== 'jobs' || ($config['retry_after'] ?? 0) < self::LEASE_SECONDS) {
            throw new LogicException('Checks require a primary database queue and an open scheduling transaction.');
        }
        $job = (new ProcessMonitorCheck($check->id))->onConnection(self::NAME)->onQueue(self::NAME)->beforeCommit();
        $jobId = Queue::connection(self::NAME)->push($job, '', self::NAME);
        $uuid = DB::table('jobs')->where('id', $jobId)->value('job_uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new LogicException('A monitor job must have a durable identifier.');
        }
        $check->forceFill(['queue_job_uuid' => $uuid])->save();
    }

    /**
     * Project → environment → monitor; callers then lock check / incident rows.
     *
     * @param  int  $id
     * @return Monitor|null
     */
    public function lockMonitor(int $id): ?Monitor
    {
        $monitor = Monitor::withTrashed()->find($id);
        $environment = $monitor === null ? null : Environment::query()->find($monitor->environment_id);
        if ($environment === null) {
            return null;
        }
        $project = Project::query()->lockForUpdate()->find($environment->project_id);
        $environment = Environment::query()->lockForUpdate()->find($environment->id);
        $monitor = Monitor::withTrashed()->lockForUpdate()->find($id);
        if ($project === null || $environment === null || $monitor === null) {
            return null;
        }
        $environment->setRelation('project', $project);
        $monitor->setRelation('environment', $environment);

        return $monitor;
    }

    /**
     * Whether a monitor should run: it is on, and its project still has Monitoring turned on.
     *
     * @param  Monitor|null  $monitor
     * @return bool
     *
     * @phpstan-assert-if-true Monitor $monitor
     */
    public function eligible(?Monitor $monitor): bool
    {
        return $monitor !== null && ! $monitor->trashed() && $monitor->enabled
            && $monitor->environment->project->hasService('monitoring');
    }

    /**
     * Deletes the check's job if no worker has picked it up yet.
     *
     * @param  MonitorCheck  $check
     * @return void
     */
    public function discardPendingJob(MonitorCheck $check): void
    {
        if ($check->queue_job_uuid !== null) {
            DB::table('jobs')->where('queue', self::NAME)->where('job_uuid', $check->queue_job_uuid)
                ->whereNull('reserved_at')->delete();
        }
    }
}
