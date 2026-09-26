<?php

namespace App\Modules\Monitor\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessMonitorCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = MonitorQueue::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $checkId) {}

    public function handle(RunMonitorCheck $runner): void
    {
        $runner->process($this->checkId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RunMonitorCheck::class)->interrupt($this->checkId);
    }
}
