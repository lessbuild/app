<?php

declare(strict_types=1);

namespace App\Jobs\Monitoring;

use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessMonitorCheck implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt: a check is a measurement at a moment, and repeating it later would record the wrong time.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * How long a check may take, which covers the slowest check type.
     *
     * @var int
     */
    public int $timeout = MonitorQueue::TIMEOUT;

    /**
     * A check that times out is failed rather than retried, for the same reason.
     *
     * @var bool
     */
    public bool $failOnTimeout = true;

    /**
     * Runs one scheduled monitor check.
     *
     * @param  string  $checkId  The queued check.
     */
    public function __construct(public readonly string $checkId) {}

    /**
     * Runs the check and records the result.
     *
     * @param  MonitorCheckRunner  $runner
     * @return void
     */
    public function handle(MonitorCheckRunner $runner): void
    {
        $runner->process($this->checkId);
    }

    /**
     * Records that the checker was interrupted, so the check reads "unknown" instead of staying queued.
     *
     * @param  Throwable|null  $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        app(MonitorCheckRunner::class)->interrupt($this->checkId);
    }
}
