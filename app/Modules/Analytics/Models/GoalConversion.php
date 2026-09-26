<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalConversion extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'goal_id', 'goal_version_id', 'analytics_event_id', 'visit_id', 'converted_at',
    ];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Goal> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return BelongsTo<GoalVersion> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(GoalVersion::class, 'goal_version_id');
    }

    /** @return BelongsTo<AnalyticsEvent> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AnalyticsEvent::class, 'analytics_event_id');
    }

    /** @return BelongsTo<Visit> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
