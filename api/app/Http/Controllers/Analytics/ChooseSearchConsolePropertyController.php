<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ChooseSearchConsoleProperty;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChooseSearchConsolePropertyController
{
    /**
     * Choose the Search Console property a site reads its search terms from.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ChooseSearchConsoleProperty  $choose
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ChooseSearchConsoleProperty $choose): JsonResponse
    {
        $property = $request->validate(['search_console_property' => ['required', 'string', 'max:255']])['search_console_property'];
        $choose->handle($user, $site, $property);

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'message' => __('Search terms now come from :property.', ['property' => $property])]);
    }
}
