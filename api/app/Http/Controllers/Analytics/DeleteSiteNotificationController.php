<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RemoveSiteNotification;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $notification, RemoveSiteNotification $remove): JsonResponse
    {
        $remove->handle($user, $site->notifications()->findOrFail($notification));

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Removed.')]);
    }
}
