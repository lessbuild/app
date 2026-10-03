<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsNotification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveSiteNotification
{
    /**
     * Stop a site's scheduled report or spike alert.
     *
     * @param  User  $actor
     * @param  AnalyticsNotification  $notification
     * @return void
     */
    public function handle(User $actor, AnalyticsNotification $notification): void
    {
        Gate::forUser($actor)->authorize('update', $notification->site);
        $notification->delete();
    }
}
