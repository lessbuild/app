<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteAudits;

use App\Actions\SiteAudits\SuggestCompetitors;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/app/projects/{project}/audit/competitor-suggestions`. */
final class SuggestCompetitorsController
{
    /**
     * Suggest a site's competitors, for the wizard's competitors step.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Request  $request
     * @param  SuggestCompetitors  $suggest
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Request $request, SuggestCompetitors $suggest): JsonResponse
    {
        $url = (string) $request->validate(['url' => ['required', 'string', 'max:2048']])['url'];

        return response()->json(['competitors' => $suggest->handle($user, $project, $url)]);
    }
}
