<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $goal_id
 * @property string $kind
 * @property string $match_type
 * @property string $match_value
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class AnalyticsGoalVersion extends Model
{
    /**
     * Stored in `analytics_goal_versions`.
     *
     * @var string|null
     */
    protected $table = 'analytics_goal_versions';

    /**
     * Written when a goal is created or changed.
     *
     * @var list<string>
     */
    protected $fillable = ['goal_id', 'kind', 'match_type', 'match_value', 'effective_from', 'effective_to'];

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }

    /**
     * Get the goal this is a definition of.
     *
     * @return BelongsTo<AnalyticsGoal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(AnalyticsGoal::class, 'goal_id');
    }
}
