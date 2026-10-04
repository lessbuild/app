<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DisconnectAdAccount;
use App\Models\AnalyticsAdAccount;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DisconnectAdAccountController
{
    /**
     * Stop reading spend from an ad account.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $account
     * @param  DisconnectAdAccount  $disconnect
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $account, DisconnectAdAccount $disconnect): JsonResponse
    {
        $disconnect->handle($user, AnalyticsAdAccount::query()->where('site_id', $site->id)->findOrFail($account));

        return response()->json(['redirect' => route('analytics.campaigns', [$project, 'site' => $site->id], false), 'message' => __('Disconnected. Spend already read stays until you remove it.')]);
    }
}
