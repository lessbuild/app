<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = ['site_id', 'name', 'kind', 'match_type', 'match_value', 'active'];

    protected $casts = ['active' => 'boolean'];

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

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<GoalVersion> */
    public function versions(): HasMany
    {
        return $this->hasMany(GoalVersion::class);
    }

    /** @return HasMany<GoalConversion> */
    public function conversions(): HasMany
    {
        return $this->hasMany(GoalConversion::class);
    }
}
