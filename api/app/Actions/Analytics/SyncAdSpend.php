<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAdAccount;
use App\Services\Analytics\AdPlatforms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncAdSpend
{
    /**
     * Create a new SyncAdSpend instance.
     *
     * @param  AdPlatforms  $platforms  Reads the platforms' reports.
     */
    public function __construct(private readonly AdPlatforms $platforms) {}

    /**
     * Read an ad account's last 30 days of spend per campaign into the site's ad spend, replacing those days for its
     * source (campaigns are summed when two accounts share one). A failure is recorded on the account. Returns how
     * many campaign days were stored, or null when the sync failed.
     *
     * @param  AnalyticsAdAccount  $account
     * @return int|null
     */
    public function handle(AnalyticsAdAccount $account): ?int
    {
        $until = CarbonImmutable::today();
        $from = $until->subDays(29);
        try {
            $rows = $this->platforms->for($account->platform)->spend($account->credential, $account->account_id, $from, $until);
        } catch (Throwable $exception) {
            $account->forceFill(['error' => mb_substr($exception->getMessage(), 0, 500), 'synced_at' => now()])->save();

            return null;
        }
        $now = now();
        $byDay = [];
        foreach ($rows as $row) {
            $key = $row['date'].'|'.mb_substr($row['campaign'], 0, 150);
            $previous = $byDay[$key] ?? ['cost_cents' => 0, 'clicks' => null, 'impressions' => null];
            $byDay[$key] = [
                'site_id' => $account->site_id, 'date' => $row['date'], 'source' => $account->source, 'campaign' => mb_substr($row['campaign'], 0, 150),
                'currency' => strtoupper($row['currency']), 'cost_cents' => $previous['cost_cents'] + (int) round($row['cost'] * 100),
                'clicks' => $row['clicks'] === null ? $previous['clicks'] : (int) ($previous['clicks'] ?? 0) + $row['clicks'],
                'impressions' => $row['impressions'] === null ? $previous['impressions'] : (int) ($previous['impressions'] ?? 0) + $row['impressions'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::transaction(function () use ($byDay): void {
            foreach (array_chunk(array_values($byDay), 500) as $chunk) {
                DB::table('analytics_ad_spend')->upsert($chunk, ['site_id', 'date', 'source', 'campaign'], ['cost_cents', 'currency', 'clicks', 'impressions', 'updated_at']);
            }
        });
        $account->forceFill(['error' => null, 'synced_at' => now()])->save();

        return count($byDay);
    }
}
