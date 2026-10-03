<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Dashboard;
use Illuminate\Database\Eloquent\Collection;

final class DashboardsQuery
{
    /**
     * Get the account's dashboards with their widget count and creator.
     *
     * @param  string  $accountId
     * @return Collection<int, Dashboard>
     */
    public function handle(string $accountId): Collection
    {
        return Dashboard::query()->where('account_id', $accountId)->withCount('widgets')->with('creator')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Find one of the account's dashboards with its widgets; 404 otherwise.
     *
     * @param  string  $accountId
     * @param  string|int  $id
     * @return Dashboard
     */
    public function find(string $accountId, int|string $id): Dashboard
    {
        return Dashboard::query()->where('account_id', $accountId)->with('widgets')->findOrFail((int) $id);
    }
}
