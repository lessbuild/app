<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteSite
{
    /**
     * Delete a site with all its events, visits, goals and reports. The tracker stops being accepted immediately.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @return void
     */
    public function handle(User $actor, AnalyticsSite $site): void
    {
        Gate::forUser($actor)->authorize('delete', $site);

        $site->delete();
    }
}
