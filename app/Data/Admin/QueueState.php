<?php

declare(strict_types=1);

namespace App\Data\Admin;

/** One queue's backlog. */
final readonly class QueueState
{
    /**
     * Create a new QueueState instance.
     *
     * A queue's waiting and running jobs.
     *
     * @param  string  $queue  The queue's name.
     * @param  int  $pending  Jobs waiting to run.
     * @param  int  $reserved  Jobs a worker is running.
     * @param  int  $oldestMinutes  Age of the oldest waiting job; 0 when none wait.
     * @param  bool  $healthy  Within the backlog and age limits.
     */
    public function __construct(public string $queue, public int $pending, public int $reserved, public int $oldestMinutes, public bool $healthy) {}

    /**
     * Get the queue as the JSON report shows it.
     *
     * @return array{queue: string, pending: int, reserved: int, oldest_minutes: int, healthy: bool}
     */
    public function toArray(): array
    {
        return ['queue' => $this->queue, 'pending' => $this->pending, 'reserved' => $this->reserved, 'oldest_minutes' => $this->oldestMinutes, 'healthy' => $this->healthy];
    }
}
