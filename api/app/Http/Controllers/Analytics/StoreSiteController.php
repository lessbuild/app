<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveSite;
use App\Http\Requests\Analytics\SiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\SetupGuideReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreSiteController
{
    /**
     * Add an analytics site and shows its tracking snippet.
     *
     * @param  SiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveSite  $save
     * @return JsonResponse
     */
    public function __invoke(SiteRequest $request, #[CurrentUser] User $user, Project $project, SaveSite $save): JsonResponse
    {
        $site = $save->handle($user, $project, $request->toDetails());

        if (($guide = SetupGuideReturn::from($request)) !== null) {

            return response()->json(['redirect' => $guide, 'message' => __('Site added. Paste its snippet into your pages to finish this step.')]);

        }

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Site added. Paste the snippet below into your pages.')]);
    }
}
