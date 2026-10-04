<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\AddSiteNotification;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreSiteNotificationController
{
    /**
     * Add a scheduled report or export, or a traffic alert, to a site and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  AddSiteNotification  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, AddSiteNotification $add): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AnalyticsNotification::KINDS))],
            'channel' => ['required', Rule::in(array_keys(AnalyticsNotification::CHANNELS))],
            'target' => ['required', 'string', 'max:500'],
            'threshold' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'saved_view_id' => ['nullable', 'integer'],
        ]);
        $add->handle($user, $site, $data['kind'], $data['channel'], $data['target'], $request->filled('threshold') ? $request->integer('threshold') : null, $request->filled('saved_view_id') ? $request->integer('saved_view_id') : null);

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Added.')]);
    }
}
