<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\InviteSiteViewer;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreSiteViewerController
{
    /**
     * Give someone view-only access to the site and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  InviteSiteViewer  $invite
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, InviteSiteViewer $invite): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $invite->handle($user, $site, $data['email']);

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Invitation sent.')]);
    }
}
