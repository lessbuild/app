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

    public int $tries = 1;

    public int $timeout = MonitorQueue::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $checkId) {}

    public function handle(MonitorCheckRunner $runner): void
    {
        $runner->process($this->checkId);
    }

    public function failed(?Throwable $exception): void
    {
        app(MonitorCheckRunner::class)->interrupt($this->checkId);
    }
}
