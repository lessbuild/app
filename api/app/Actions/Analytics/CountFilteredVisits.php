<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Support\Facades\DB;

final class CountFilteredVisits
{
    /**
     * Why collection leaves events out, with their labels.
     *
     * @var array<string, string>
     */
    public const REASONS = ['bot' => 'Bots and crawlers', 'spam' => 'Referrer spam', 'ignored' => 'Ignored addresses'];

    /**
     * Add to today's count (in the site's timezone) of events left out for a reason.
     *
     * @param  AnalyticsSite  $site
     * @param  string  $reason  one of REASONS' keys
     * @param  int  $count
     * @return void
     */
    public function handle(AnalyticsSite $site, string $reason, int $count = 1): void
    {
        if ($count < 1 || ! array_key_exists($reason, self::REASONS)) {
            return;
        }
        $now = now();
        $date = $now->copy()->setTimezone($site->timezone)->toDateString();
        DB::table('analytics_filtered_counts')->insertOrIgnore(['site_id' => $site->id, 'date' => $date, 'reason' => $reason, 'count' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('analytics_filtered_counts')->where('site_id', $site->id)->where('date', $date)->where('reason', $reason)->increment('count', $count, ['updated_at' => $now]);
    }
}
