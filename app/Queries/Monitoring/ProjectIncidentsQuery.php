<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Incident;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

final class ProjectIncidentsQuery
{
    /**
     * The project's incidents, newest first: open and acknowledged ones by default, or resolved ones when asked.
     *
     * @param  Project  $project
     * @param  string  $status
     * @return list<Incident> open (or acknowledged) incidents first by default; resolved ones on request
     */
    public function handle(Project $project, string $status = 'open'): array
    {
        $query = Incident::query()->where('project_id', $project->id)->with(['monitor' => fn ($monitor) => $monitor->withTrashed(), 'assignee']);
        $status === 'resolved' ? $query->where('status', 'resolved') : $query->where('status', '!=', 'resolved');

        return array_values($query->latest('opened_at')->latest('id')->limit(100)->get()->all());
    }

    /**
     * An incident of this project (404 otherwise).
     *
     * @param  Project  $project
     * @param  string|int  $id
     * @return Incident
     */
    public function find(Project $project, string|int $id): Incident
    {
        $incident = Incident::query()->where('project_id', $project->id)->with(['assignee', 'acknowledgedBy'])->findOrFail((int) $id);
        $incident->setRelation('project', $project);

        return $incident;
    }

    /**
     * Members an incident can be assigned to: owners, admins and members who may use Monitoring, by name.
     *
     * @param  Project  $project
     * @return list<User> members who can be assigned incidents: they work on projects and may use Monitoring
     */
    public function assignees(Project $project): array
    {
        $memberships = Membership::query()->where('account_id', $project->account_id)->with('user')->get()
            ->filter(fn (Membership $membership): bool => $membership->canTakeMonitoringAssignments());

        return array_values($memberships->map(fn (Membership $membership): User => $membership->user)
            ->sortBy(fn (User $user): string => mb_strtolower($user->name))->all());
    }
}
