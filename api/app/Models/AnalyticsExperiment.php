<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An A/B test on a site: the page asks for a variant with buildpusher.variant(key, variants), and the goal decides
 * which variant converts better. The first variant is the control.
 *
 * @property int $id
 * @property int $site_id
 * @property string $key the name the page uses
 * @property string $name
 * @property list<string> $variants
 * @property int|null $goal_id
 * @property string $status running or stopped
 * @property Carbon $started_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsGoal|null $goal
 */
final class AnalyticsExperiment extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; experiments are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['variants' => 'array', 'started_at' => 'datetime', 'stopped_at' => 'datetime'];
    }

    /**
     * Get the goal that decides the experiment.
     *
     * @return BelongsTo<AnalyticsGoal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(AnalyticsGoal::class, 'goal_id');
    }
}
