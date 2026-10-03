<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SyncAdSpend;
use App\Models\AnalyticsAdAccount;
use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

final class SyncAdAccountController
{
    /**
     * Read an ad account's spend now.
     *
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $account
     * @param  SyncAdSpend  $sync
     * @return RedirectResponse
     */
    public function __invoke(Project $project, AnalyticsSite $site, int $account, SyncAdSpend $sync): RedirectResponse
    {
        $adAccount = AnalyticsAdAccount::query()->where('site_id', $site->id)->findOrFail($account);
        $days = $sync->handle($adAccount);

        return to_route('analytics.campaigns', [$project, 'site' => $site->id])
            ->with($days === null ? 'error' : 'status', $days === null ? (string) $adAccount->error : trans_choice('Read :count campaign day.|Read :count campaign days.', $days, ['count' => $days]));
    }
}
