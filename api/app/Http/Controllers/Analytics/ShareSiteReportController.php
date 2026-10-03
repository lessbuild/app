<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ShareSiteReport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
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
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ShareSiteReport $share): RedirectResponse
    {
        $validated = $request->validate([
            'share_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'new_link' => ['nullable', 'boolean'],
        ]);
        $share->handle($user, $site, $validated['share_password'] ?? null, (bool) ($validated['new_link'] ?? false));

        return to_route('analytics.sites.show', [$project, $site])->with('status', __('The report is shared. Anyone with the link can see it.'));
    }
}
