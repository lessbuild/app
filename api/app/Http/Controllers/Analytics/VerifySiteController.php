<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\VerifySite;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class VerifySiteController
{
    /**
     * Check the site's hostnames against the project's verified domains and says what to do if none matches.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  VerifySite  $verify
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, VerifySite $verify): JsonResponse
    {
        return $verify->handle($user, $site)
            ? response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'message' => __('Verified. Data starts appearing as soon as visitors arrive.')])
            : response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'warning' => __('None of this site’s hostnames is a verified domain of the project yet. Verify one under Domains, then check again.')]);
    }
}
