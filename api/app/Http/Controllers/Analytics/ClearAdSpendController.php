<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ClearAdSpend;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClearAdSpendController
{
    /**
     * Remove a site's imported ad spend (from one source, when given) and return to the campaigns page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ClearAdSpend  $clear
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ClearAdSpend $clear): JsonResponse
    {
        $source = $request->input('source');
        $clear->handle($user, $site, is_string($source) && $source !== '' ? $source : null);

        return response()->json(['redirect' => route('analytics.campaigns', [$project, 'site' => $site->id], false), 'message' => __('Ad spend removed.')]);
    }
}
