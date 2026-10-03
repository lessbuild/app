<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Exceptions\AccountRuleViolation;
use App\Jobs\Security\RunSecurityScan;
use App\Models\Project;
use App\Models\SecurityScan;
use App\Models\User;
use App\Services\Security\Scanners;
use Illuminate\Support\Facades\Gate;

final class QueueSecurityScan
{
    /**
     * Create a new QueueSecurityScan instance.
     *
     * @param  Scanners  $scanners  Finds the scanner and checks the plan includes it.
     */
    public function __construct(private readonly Scanners $scanners) {}

    /**
     * Queue a scan of one kind for a project, by someone ("Scan now") or the schedule (no actor). A scan of the same
     * kind already waiting or running is returned instead of starting another.
     *
     * @param  Project  $project
     * @param  string  $kind
     * @param  User|null  $actor
     * @return SecurityScan
     */
    public function handle(Project $project, string $kind, ?User $actor = null): SecurityScan
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        }
        $scanner = $this->scanners->find($kind);
        if ($scanner === null || ! $this->scanners->included($project->account, $scanner)) {
            throw new AccountRuleViolation('kind', __('Your Security plan doesn’t include this check.'));
        }
        $pending = SecurityScan::query()->where('project_id', $project->id)->where('kind', $kind)->whereIn('status', ['queued', 'running'])->first();
        if ($pending !== null) {
            return $pending;
        }
        $scan = new SecurityScan;
        $scan->forceFill(['project_id' => $project->id, 'kind' => $kind, 'status' => 'queued', 'requested_by' => $actor?->id])->save();
        RunSecurityScan::dispatch($scan->id)->afterCommit();

        return $scan;
    }
}
