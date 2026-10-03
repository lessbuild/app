<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone covering an on-call schedule for a while, instead of whoever's turn it is.
 *
 * @property int $id
 * @property int $on_call_schedule_id
 * @property string $user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OnCallSchedule $schedule
 * @property-read User $user
 */
class OnCallOverride extends Model
{
    /**
     * Get the schedule it overrides.
     *
     * @return BelongsTo<OnCallSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'on_call_schedule_id');
    }

    /**
     * Get who's covering.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the period as dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
