<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalVersion extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = ['goal_id', 'kind', 'match_type', 'match_value', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /** @return BelongsTo<Goal> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
