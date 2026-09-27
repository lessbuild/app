<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Dashboard;
use Illuminate\Database\Eloquent\Collection;

final class DashboardsQuery
{
    /** @return Collection<int, Dashboard> */
    public function handle(string $accountId): Collection
    {
        return Dashboard::query()->where('account_id', $accountId)->withCount('widgets')->with('creator')->orderBy('name')->orderBy('id')->get();
    }

    public function find(string $accountId, int|string $id): Dashboard
    {
        return Dashboard::query()->where('account_id', $accountId)->with('widgets')->findOrFail((int) $id);
    }
}
