<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property int $goal_id
 * @property Carbon $converted_at
 */
class AnalyticsGoalConversion extends Model
{
    protected $table = 'analytics_goal_conversions';

    /** @var list<string> */
    protected $fillable = ['site_id', 'goal_id', 'goal_version_id', 'analytics_event_id', 'visit_id', 'converted_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }

    /** @return BelongsTo<AnalyticsSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /** @return BelongsTo<AnalyticsGoal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(AnalyticsGoal::class, 'goal_id');
    }

    /** @return BelongsTo<AnalyticsGoalVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AnalyticsGoalVersion::class, 'goal_version_id');
    }

    /** @return BelongsTo<AnalyticsEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AnalyticsEvent::class, 'analytics_event_id');
    }

    /** @return BelongsTo<AnalyticsVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisit::class, 'visit_id');
    }
}
