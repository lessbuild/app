<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Data\Projects\ServiceProjectRow;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** A service's account-level page: which projects use it. Projects using it come first. */
final class ServiceProjectsQuery
{
    /** @return list<ServiceProjectRow> */
    public function handle(Account $account, string $service, User $viewer): array
    {
        $gate = Gate::forUser($viewer);

        return array_values(Project::query()
            ->where('account_id', $account->id)
            ->withExists(['enabledServices as service_enabled' => fn ($query) => $query->where('service', $service)])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): ServiceProjectRow => new ServiceProjectRow(
                projectId: $project->id,
                projectName: $project->name,
                enabled: (bool) $project->getAttribute('service_enabled'),
                canManage: $gate->allows('manageService', [$project, $service]),
            ))
            ->sortByDesc(fn (ServiceProjectRow $row): bool => $row->enabled)
            ->all());
    }
}
