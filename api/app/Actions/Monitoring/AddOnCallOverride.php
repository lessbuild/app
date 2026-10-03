<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\OnCallOverride;
use App\Models\OnCallSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AddOnCallOverride
{
    /**
     * Put a verified member on call for a period instead of whoever's turn it is, such as while someone is away.
     *
     * @param  User  $actor
     * @param  OnCallSchedule  $schedule
     * @param  string  $userId
     * @param  CarbonImmutable  $startsAt
     * @param  CarbonImmutable  $endsAt
     * @return OnCallOverride
     */
    public function handle(User $actor, OnCallSchedule $schedule, string $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): OnCallOverride
    {
        Gate::forUser($actor)->authorize('update', $schedule);
        if (! $schedule->account->members()->whereNotNull('email_verified_at')->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => __('Choose a verified member of this account.')]);
        }
        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->isPast() || $startsAt->diffInDays($endsAt) > 90) {
            throw ValidationException::withMessages(['ends_at' => __('The cover must end after it starts, in the future, and last at most 90 days.')]);
        }
        $override = new OnCallOverride;
        $override->forceFill(['on_call_schedule_id' => $schedule->id, 'user_id' => $userId, 'starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'created_by' => $actor->id])->save();

        return $override;
    }
}
