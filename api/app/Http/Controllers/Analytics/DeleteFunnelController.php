<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DeleteFunnel;
use App\Models\AnalyticsFunnel;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteFunnelController
{
    /**
     * Delete a funnel and return to the site's funnels.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $funnel
     * @param  DeleteFunnel  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $funnel, DeleteFunnel $delete): JsonResponse
    {
        $delete->handle($user, AnalyticsFunnel::query()->where('site_id', $site->id)->findOrFail($funnel));

        return response()->json(['redirect' => route('analytics.funnels', [$project, 'site' => $site->id], false), 'message' => __('Funnel removed.')]);
    }
}
