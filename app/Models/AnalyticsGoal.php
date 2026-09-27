<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pageview path or custom event counted as a conversion. Every change to what it matches starts a new
 * version, so past conversions keep the definition that was live when they happened.
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string $kind pageview or event
 * @property string $match_type exact, prefix or contains
 * @property string $match_value
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsSite $site
 */
class AnalyticsGoal extends Model
{
    /**
     * Stored in `analytics_goals`.
     */
    protected $table = 'analytics_goals';

    /**
     * The goal's settings from its form.
     *
     * @var list<string>
     */
    protected $fillable = ['site_id', 'name', 'kind', 'match_type', 'match_value', 'active'];

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * Records a version of the goal's definition when it's created and each time its kind or match changes (closing
     * the previous one), so its definition can be looked up for any point in time.
     */
    protected static function booted(): void
    {
        static::created(function (self $goal): void {
            $goal->versions()->create([
                'kind' => $goal->kind,
                'match_type' => $goal->match_type,
                'match_value' => $goal->match_value,
                'effective_from' => $goal->created_at ?? now(),
            ]);
        });

        static::updated(function (self $goal): void {
            if (! $goal->wasChanged(['kind', 'match_type', 'match_value'])) {
                return;
            }
            $effectiveFrom = $goal->updated_at ?? now();
            $goal->versions()->whereNull('effective_to')->update(['effective_to' => $effectiveFrom]);
            $goal->versions()->create([
                'kind' => $goal->kind,
                'match_type' => $goal->match_type,
                'match_value' => $goal->match_value,
                'effective_from' => $effectiveFrom,
            ]);
        });
    }

    /**
     * The site the goal is measured on.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /**
     * The goal's definitions over time.
     *
     * @return HasMany<AnalyticsGoalVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AnalyticsGoalVersion::class, 'goal_id');
    }

    /**
     * Visits that completed the goal.
     *
     * @return HasMany<AnalyticsGoalConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(AnalyticsGoalConversion::class, 'goal_id');
    }

    /**
     * The goal's definition in effect at a moment: the version whose period includes it. Null before the goal existed.
     * Callers that check many events should eager-load `versions`.
     */
    public function versionAt(mixed $at): ?AnalyticsGoalVersion
    {
        $at = CarbonImmutable::parse($at);

        return $this->versions->first(fn (AnalyticsGoalVersion $version): bool => $at->greaterThanOrEqualTo($version->effective_from)
            && ($version->effective_to === null || $at->lessThan($version->effective_to)));
    }

    /**
     * Whether the event completes the goal as it was defined when the event happened, so editing a goal doesn't rewrite
     * history. Event goals match a custom event's name; path goals match a pageview's path exactly or by prefix. Goals
     * without recorded versions use their current definition.
     */
    public function isCompletedBy(AnalyticsEvent $event): bool
    {
        $version = $this->versionAt($event->occurred_at);
        if ($this->versions->isNotEmpty() && $version === null) {
            return false;
        }
        $kind = $version->kind ?? $this->kind;
        $matchType = $version->match_type ?? $this->match_type;
        $matchValue = $version->match_value ?? $this->match_value;
        if ($kind === 'event') {
            return $event->type === 'event' && data_get($event->properties, 'name') === $matchValue;
        }

        return $event->type === 'pageview' && ($matchType === 'prefix' ? str_starts_with($event->path, $matchValue) : $event->path === $matchValue);
    }
}
