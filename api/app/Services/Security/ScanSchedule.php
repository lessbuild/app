<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Actions\Security\QueueSecurityScan;
use App\Models\Project;
use App\Models\SecurityScan;

/** Queues the Security scans that are due, as often as each account's plan allows. */
final class ScanSchedule
{
    /**
     * Create a new ScanSchedule instance.
     *
     * @param  Scanners  $scanners  The checks and plan limits.
     * @param  QueueSecurityScan  $queue  Queues each scan.
     */
    public function __construct(private readonly Scanners $scanners, private readonly QueueSecurityScan $queue) {}

    /**
     * Queue every included check whose last scan is older than the plan's interval, for projects with Security on.
     * Returns how many were queued.
     *
     * @return int
     */
    public function queueDue(): int
    {
        $queued = 0;
        Project::query()->whereHas('enabledServices', fn ($query) => $query->where('service', 'security'))->with('account')->orderBy('id')
            ->each(function (Project $project) use (&$queued): void {
                $hours = $this->scanners->intervalHours($project->account);
                foreach ($this->scanners->all() as $kind => $scanner) {
                    if (! $this->scanners->included($project->account, $scanner)) {
                        continue;
                    }
                    $last = SecurityScan::query()->where('project_id', $project->id)->where('kind', $kind)->latest('id')->first();
                    if ($last !== null && ($last->created_at?->gt(now()->subHours($hours)) ?? false)) {
                        continue;
                    }
                    $this->queue->handle($project, $kind);
                    $queued++;
                }
            });

        return $queued;
    }
}
