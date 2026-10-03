<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSiteViewer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveSiteViewer
{
    /**
     * Take away someone's view-only access; their link stops working at once.
     *
     * @param  User  $actor
     * @param  AnalyticsSiteViewer  $viewer
     * @return void
     */
    public function handle(User $actor, AnalyticsSiteViewer $viewer): void
    {
        Gate::forUser($actor)->authorize('update', $viewer->site);
        $viewer->delete();
    }
}
