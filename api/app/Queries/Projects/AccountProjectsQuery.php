<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Data\Projects\ProjectCard;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;

final class AccountProjectsQuery
{
    /**
     * Create a new AccountProjectsQuery instance.
     *
     * Lists the account's projects.
     *
     * @param  ServiceRegistry  $services  Orders and names each project's enabled services.
     * @param  VisibleProjects  $visible  Limits the list to the projects the person can see.
     */
    public function __construct(private readonly ServiceRegistry $services, private readonly VisibleProjects $visible) {}

    /**
     * Get the account's projects by name, with their enabled services in registry order and environment count.
     *
     * @param  Account  $account
     * @param  User|null  $user  limits the list to the projects they can see
     * @return list<ProjectCard>
     */
    public function handle(Account $account, ?User $user = null): array
    {
        $order = array_flip($this->services->keys());

        return array_values($this->visible->scope(Project::query()->where('account_id', $account->id), $account->id, $user)
            ->with('enabledServices')
            ->withCount('environments')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): ProjectCard => new ProjectCard(
                id: $project->id,
                name: $project->name,
                description: $project->description,
                serviceNames: array_values($project->enabledServices
                    ->pluck('service')
                    ->filter(fn (string $key): bool => isset($order[$key]))
                    ->sortBy(fn (string $key): int => $order[$key])
                    ->map(fn (string $key): string => $this->services->find($key)?->name() ?? $key)
                    ->all()),
                environmentCount: (int) $project->getAttribute('environments_count'),
            ))
            ->all());
    }
}
