<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $visit_key
 * @property Carbon $started_at
 * @property Carbon $last_seen_at
 * @property int $pageviews
 * @property int $conversion_count
 */
class AnalyticsVisit extends Model
{
    protected $table = 'analytics_visits';

    /** @var list<string> */
    protected $fillable = ['site_id', 'visit_key', 'visitor_hash', 'session_id', 'started_at', 'last_seen_at', 'landing_path', 'exit_path', 'entry_referrer_host', 'entry_utm_source', 'entry_utm_medium', 'entry_utm_campaign', 'pageviews', 'conversion_count'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<AnalyticsSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }
}
