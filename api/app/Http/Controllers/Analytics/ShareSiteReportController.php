<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ShareSiteReport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShareSiteReportController
{
    /**
     * Share a site's report by link, or change its password or link.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ShareSiteReport  $share
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ShareSiteReport $share): JsonResponse
    {
        $validated = $request->validate([
            'share_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'new_link' => ['nullable', 'boolean'],
        ]);
        $share->handle($user, $site, $validated['share_password'] ?? null, (bool) ($validated['new_link'] ?? false));

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'message' => __('The report is shared. Anyone with the link can see it.')]);
    }
}
