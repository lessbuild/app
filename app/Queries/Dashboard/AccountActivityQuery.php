<?php

declare(strict_types=1);

namespace App\Queries\Dashboard;

use App\Data\Dashboard\ActivityItem;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Project;
use Carbon\CarbonImmutable;

/** What the team has been doing across an account's projects: deploys, incidents and changes, newest first. */
final class AccountActivityQuery
{
    /**
     * The feed's filters, as kind => label key.
     *
     * @var array<string, string>
     */
    public const KINDS = ['deploy' => 'Deploys', 'incident' => 'Incidents', 'change' => 'Changes'];

    /**
     * Get the account's latest activity, newest first. Deploys and incidents come from their own records (so a
     * webhook deploy or an automatic incident shows as well as people's actions); changes come from the audit log,
     * without its addresses and devices.
     *
     * @param  Account  $account
     * @param  string|null  $kind  one of KINDS to show only that kind, or null for everything
     * @param  int  $limit
     * @return list<ActivityItem>
     */
    public function handle(Account $account, ?string $kind = null, int $limit = 15): array
    {
        $projects = Project::query()->whereBelongsTo($account)->pluck('name', 'id')->all();
        $items = [
            ...($kind === null || $kind === 'deploy' ? $this->deploys(array_keys($projects), $projects, $limit) : []),
            ...($kind === null || $kind === 'incident' ? $this->incidents($account, $projects, $limit) : []),
            ...($kind === null || $kind === 'change' ? $this->changes($account, $projects, $limit) : []),
        ];
        usort($items, fn (ActivityItem $a, ActivityItem $b): int => $b->at <=> $a->at);

        return array_slice($items, 0, $limit);
    }

    /**
     * Get the latest deploys in the account's projects, each at the moment it last changed state.
     *
     * @param  list<string>  $projectIds
     * @param  array<string, string>  $projects  project id => name
     * @param  int  $limit
     * @return array<int, ActivityItem>
     */
    private function deploys(array $projectIds, array $projects, int $limit): array
    {
        if ($projectIds === []) {
            return [];
        }

        return Build::query()
            ->with(['repository:id,name,project_id', 'environment:id,name', 'requester:id,name'])
            ->whereHas('repository', fn ($query) => $query->whereIn('project_id', $projectIds))
            ->latest('id')->limit($limit)->get()
            ->map(function (Build $build) use ($projects): ActivityItem {
                [$tone, $outcome] = match ($build->status) {
                    Build::STATUS_SUCCEEDED => ['success', __('Live')],
                    Build::STATUS_FAILED => ['danger', __('Failed')],
                    Build::STATUS_AWAITING_APPROVAL => ['warning', __('Needs approval')],
                    Build::STATUS_CANCELED, Build::STATUS_REJECTED => ['neutral', __('Stopped')],
                    default => ['info', __('Deploying')],
                };
                $projectId = $build->repository->project_id;

                return new ActivityItem(
                    kind: 'deploy', icon: 'cloud-upload', tone: $tone, outcome: $outcome,
                    title: $build->environment !== null
                        ? __('Deploy #:id of :repository to :environment', ['id' => $build->id, 'repository' => $build->repository->name, 'environment' => $build->environment->name])
                        : __('Deploy #:id of :repository', ['id' => $build->id, 'repository' => $build->repository->name]),
                    actor: $build->requester?->name,
                    project: $projects[$projectId] ?? null,
                    url: route('deploy.builds.show', [$projectId, $build->id]),
                    at: CarbonImmutable::instance($build->finished_at ?? $build->started_at ?? $build->created_at ?? now()),
                );
            })->values()->all();
    }

    /**
     * Get the account's latest incidents, each at the moment it opened or was resolved.
     *
     * @param  Account  $account
     * @param  array<string, string>  $projects  project id => name
     * @param  int  $limit
     * @return array<int, ActivityItem>
     */
    private function incidents(Account $account, array $projects, int $limit): array
    {
        return Incident::query()->whereBelongsTo($account)->whereNotNull('project_id')
            ->latest('opened_at')->latest('id')->limit($limit)->get()
            ->map(fn (Incident $incident): ActivityItem => new ActivityItem(
                kind: 'incident', icon: 'alert',
                tone: $incident->resolved_at !== null ? 'success' : 'danger',
                outcome: $incident->resolved_at !== null ? __('Resolved') : __('Open'),
                title: $incident->title,
                actor: null,
                project: $projects[(string) $incident->project_id] ?? null,
                url: route('monitoring.incidents.show', [(string) $incident->project_id, $incident->id]),
                at: $incident->resolved_at ?? $incident->opened_at,
            ))->values()->all();
    }

    /**
     * Get the latest changes people made in the account, from the audit log. Sign-in and security entries are
     * personal, so they stay in the audit log.
     *
     * @param  Account  $account
     * @param  array<string, string>  $projects  project id => name
     * @param  int  $limit
     * @return array<int, ActivityItem>
     */
    private function changes(Account $account, array $projects, int $limit): array
    {
        return AuditEntry::query()->where('account_id', $account->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit * 3)->get()
            ->reject(fn (AuditEntry $entry): bool => $entry->action->category() === 'security')->take($limit)
            ->map(fn (AuditEntry $entry): ActivityItem => new ActivityItem(
                kind: 'change', icon: 'settings', tone: 'neutral', outcome: __('Change'),
                title: $entry->action->describe($entry->context ?? []),
                actor: $entry->actor_name,
                project: $entry->project_id !== null ? ($projects[$entry->project_id] ?? null) : null,
                url: $entry->project_id !== null && isset($projects[$entry->project_id]) ? route('projects.show', $entry->project_id) : null,
                at: CarbonImmutable::instance($entry->created_at),
            ))->values()->all();
    }
}
