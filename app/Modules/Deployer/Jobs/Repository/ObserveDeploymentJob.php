<?php

namespace App\Modules\Deployer\Jobs\Repository;

use App\Modules\Deployer\Actions\Repository\RunDeploymentObservationAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ObserveDeploymentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 120;

    /**
     * Capture only the observation identity so workers reload the current lease and target.
     *
     * @param  int  $observationId  Revision-bound observation to probe.
     */
    public function __construct(public readonly int $observationId) {}

    /** @return string Observation identifier used by Laravel's unique-job lock. */
    public function uniqueId(): string
    {
        return (string) $this->observationId;
    }

    /**
     * Run one leased observation attempt; stale, duplicate and terminal work is acknowledged as a no-op.
     *
     * @param  RunDeploymentObservationAction  $observations  Observation state machine and remote probe boundary.
     */
    public function handle(RunDeploymentObservationAction $observations): void
    {
        $observations->handle($this->observationId, $this->attempts(), $this->tries);
    }
}
