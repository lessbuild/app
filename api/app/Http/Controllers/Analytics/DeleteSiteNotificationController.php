<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RemoveSiteNotification;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteSiteNotificationController
{
    /**
     * Stop one of a site's reports or alerts and return to its settings. Another site's are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $notification
     * @param  RemoveSiteNotification  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $notification, RemoveSiteNotification $remove): RedirectResponse
    {
        $remove->handle($user, $site->notifications()->findOrFail($notification));

        return to_route('analytics.sites.show', [$project, $site->id])->with('status', __('Removed.'));
    }
}
