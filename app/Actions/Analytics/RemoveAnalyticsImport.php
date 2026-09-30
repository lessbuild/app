<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RemoveAnalyticsImport
{
    /**
     * Remove an import and the daily totals it brought in (imports only cover days before the site's own data, so
     * nothing collected is touched), then work out how far back the remaining imports reach.
     *
     * @param  User  $actor
     * @param  AnalyticsImport  $import
     * @return void
     */
    public function handle(User $actor, AnalyticsImport $import): void
    {
        $site = $import->site;
        Gate::forUser($actor)->authorize('update', $site);
        DB::transaction(function () use ($import, $site): void {
            if ($import->status === 'done' && $import->from_date !== null && $import->until_date !== null && $import->days_imported > 0) {
                AnalyticsDailyAggregate::query()->where('site_id', $site->id)
                    ->whereDate('local_date', '>=', $import->from_date->toDateString())->whereDate('local_date', '<=', $import->until_date->toDateString())
                    ->delete();
            }
            $import->delete();
            $until = $site->imports()->where('status', 'done')->where('days_imported', '>', 0)->max('until_date');
            $site->forceFill(['imported_until' => $until])->save();
        });
    }
}
