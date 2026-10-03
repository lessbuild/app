<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An on-call rotation: members take turns, a day or a week each, handing over at a set local time. Overrides put
 * someone else on call for a while.
 *
 * @property int $id
 * @property string $account_id
 * @property string $name
 * @property string $timezone
 * @property string $rotation daily or weekly
 * @property string $handoff_time HH:MM in the schedule's time zone
 * @property int|null $handoff_day ISO weekday (1 = Monday) weekly rotations hand over on
 * @property Carbon $starts_on the day the first member's turn begins
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $members
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OnCallOverride> $overrides
 */
class OnCallSchedule extends Model
{
    /**
     * Get the account the schedule belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the members in the order they take turns.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'on_call_schedule_members')->withPivot('position')->orderByPivot('position');
    }

    /**
     * Get the overrides that put someone else on call for a while.
     *
     * @return HasMany<OnCallOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(OnCallOverride::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the start day as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'handoff_day' => 'integer'];
    }
}
