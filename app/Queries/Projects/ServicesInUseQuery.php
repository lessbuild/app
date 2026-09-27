<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Account;
use App\Models\EnabledService;
use App\Models\Project;

final class ServicesInUseQuery
{
    /**
     * The services turned on in at least one of the account's projects, for billing.
     *
     * @return list<string> service keys enabled on at least one of the account's projects
     */
    public function handle(Account $account): array
    {
        return array_values(EnabledService::query()
            ->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'))
            ->distinct()
            ->pluck('service')
            ->map(fn ($service): string => (string) $service)
            ->all());
    }
}
