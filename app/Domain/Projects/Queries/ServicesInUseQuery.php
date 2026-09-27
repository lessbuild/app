<?php

declare(strict_types=1);

namespace App\Domain\Projects\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Projects\Models\EnabledService;
use App\Domain\Projects\Models\Project;

final class ServicesInUseQuery
{
    /** @return list<string> service keys enabled on at least one of the account's projects */
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
