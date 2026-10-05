<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Incident;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ProjectIncidentsQuery
{
    /**
     * Get the project's incidents, newest first: open and acknowledged ones by default, or resolved ones when asked.
     *
     * @param  Project  $project
     * @param  string  $status
     * @return list<Incident> open (or acknowledged) incidents first by default; resolved ones on request
     */
    public function handle(Project $project, string $status = 'open'): array
    {
        $query = Incident::query()->where('project_id', $project->id)->with(['monitor' => fn ($monitor) => $monitor->withTrashed()->with('environment'), 'alertRule.environment', 'assignee']);
        $status === 'resolved' ? $query->where('status', 'resolved') : $query->where('status', '!=', 'resolved');

        return array_values($query->latest('opened_at')->latest('id')->limit(100)->get()->all());
    }

    /**
     * Sum up the project's incidents: how many are open, how long they took to acknowledge and to resolve on average
     * over the last 30 days (in seconds, null without any), and how many opened in the last 30 days and the 30 before.
     *
     * @param  Project  $project
     * @return array{open: int, resolved: int, acknowledgeSeconds: int|null, resolveSeconds: int|null, last30: int, before30: int}
     */
    public function stats(Project $project): array
    {
        $now = CarbonImmutable::now('UTC');
        $recent = Incident::query()->where('project_id', $project->id)->where('opened_at', '>=', $now->subDays(30))->get(['opened_at', 'acknowledged_at', 'resolved_at']);
        $average = function (string $column) use ($recent): ?int {
            $durations = $recent->filter(fn (Incident $incident): bool => $incident->{$column} !== null)
                ->map(fn (Incident $incident): float => $incident->opened_at->diffInSeconds($incident->{$column}, true));

            return $durations->isEmpty() ? null : (int) round((float) $durations->avg());
        };

        return [
            'open' => Incident::query()->where('project_id', $project->id)->where('status', '!=', 'resolved')->count(),
            'resolved' => Incident::query()->where('project_id', $project->id)->where('status', 'resolved')->count(),
            'acknowledgeSeconds' => $average('acknowledged_at'),
            'resolveSeconds' => $average('resolved_at'),
            'last30' => $recent->count(),
            'before30' => Incident::query()->where('project_id', $project->id)->where('opened_at', '>=', $now->subDays(60))->where('opened_at', '<', $now->subDays(30))->count(),
        ];
    }

    /**
     * Find an incident of this project (404 otherwise).
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
     * Get the members an incident can be assigned to: owners, admins and members who may use Monitoring, by name.
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
