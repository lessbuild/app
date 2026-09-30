<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAdSpend;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ClearAdSpend
{
    /**
     * Remove a site's imported ad spend, from one source or all of them. Returns how many rows were removed.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string|null  $source
     * @return int
     */
    public function handle(User $actor, AnalyticsSite $site, ?string $source): int
    {
        Gate::forUser($actor)->authorize('update', $site);

        return AnalyticsAdSpend::query()->where('site_id', $site->id)->when($source !== null, fn ($query) => $query->where('source', mb_strtolower((string) $source)))->delete();
    }
}
