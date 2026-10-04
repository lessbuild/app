<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SyncAdSpend;
use App\Models\AnalyticsAdAccount;
use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

final class SyncAdAccountController
{
    /**
     * Read an ad account's spend now.
     *
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $account
     * @param  SyncAdSpend  $sync
     * @return JsonResponse
     */
    public function __invoke(Project $project, AnalyticsSite $site, int $account, SyncAdSpend $sync): JsonResponse
    {
        $adAccount = AnalyticsAdAccount::query()->where('site_id', $site->id)->findOrFail($account);
        $days = $sync->handle($adAccount);

        return $days === null
            ? response()->json(['redirect' => route('analytics.campaigns', [$project, 'site' => $site->id], false), 'warning' => (string) $adAccount->error])
            : response()->json(['redirect' => route('analytics.campaigns', [$project, 'site' => $site->id], false), 'message' => trans_choice('Read :count campaign day.|Read :count campaign days.', $days, ['count' => $days])]);
    }
}
