<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Models\HeartbeatRun;
use App\Models\Incident;
use App\Models\MonitorCheck;
use App\Models\QueueSnapshot;
use App\Models\QueueWorker;

/** What a monitor's page shows below its settings. */
final readonly class MonitorHistory
{
    /**
     * Create a new MonitorHistory instance.
     *
     * A monitor's recent history for its detail page.
     *
     * @param  list<MonitorCheck>  $checks  newest first
     * @param  list<Incident>  $incidents  newest first
     * @param  list<HeartbeatRun>  $runs  heartbeat monitors only, newest first
     * @param  ?QueueSnapshot  $snapshot  The latest queue report, for queue monitors.
     * @param  list<QueueWorker>  $workers  queue monitors only: workers seen recently
     */
    public function __construct(
        public array $checks,
        public array $incidents,
        public array $runs,
        public ?QueueSnapshot $snapshot,
        public array $workers,
    ) {}
}
