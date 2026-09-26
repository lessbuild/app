<?php

declare(strict_types=1);

namespace App\Domain\Projects\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Projects\Data\ProjectCard;
use App\Domain\Projects\Models\Project;
use App\Platform\ServiceRegistry;

final class AccountProjectsQuery
{
    public function __construct(private readonly ServiceRegistry $services) {}

    /** @return list<ProjectCard> */
    public function handle(Account $account): array
    {
        $order = array_flip($this->services->keys());

        return array_values(Project::query()
            ->where('account_id', $account->id)
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
