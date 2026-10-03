<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\OnCallOverride;
use App\Models\OnCallSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Works out who's on call: the member whose turn it is in a rotation, unless an override covers that moment. Turns
 * are counted in local calendar days, so hand-overs stay at the same local time across daylight-saving changes.
 */
final class OnCall
{
    /**
     * Get who's on call at a moment (now by default), or null when the schedule has no members.
     *
     * @param  OnCallSchedule  $schedule
     * @param  CarbonImmutable|null  $at
     * @return User|null
     */
    public function current(OnCallSchedule $schedule, ?CarbonImmutable $at = null): ?User
    {
        $at ??= CarbonImmutable::now('UTC');
        $override = $schedule->overrides()->with('user')->where('starts_at', '<=', $at->utc())->where('ends_at', '>', $at->utc())->latest('id')->first();
        if ($override instanceof OnCallOverride) {
            return $override->user;
        }

        return $this->shift($schedule, $at)['user'];
    }

    /**
     * Get the rotation's turn that covers a moment: who, and when it starts and ends (ignoring overrides).
     *
     * @param  OnCallSchedule  $schedule
     * @param  CarbonImmutable  $at
     * @return array{user: User|null, starts: CarbonImmutable, ends: CarbonImmutable}
     */
    public function shift(OnCallSchedule $schedule, CarbonImmutable $at): array
    {
        $days = $schedule->rotation === 'weekly' ? 7 : 1;
        [$hour, $minute] = array_map('intval', explode(':', $schedule->handoff_time) + [1 => '0']);
        $local = $at->setTimezone($schedule->timezone);
        $start = $local->setTime($hour, $minute);
        if ($days === 7) {
            $start = $start->subDays(($start->dayOfWeekIso - ($schedule->handoff_day ?? 1) + 7) % 7);
        }
        if ($start->greaterThan($local)) {
            $start = $start->subDays($days);
        }
        $anchor = CarbonImmutable::parse($schedule->starts_on->format('Y-m-d'), $schedule->timezone);
        $turn = intdiv((int) round($anchor->diffInDays($start->startOfDay(), false)) + ($days * 10000), $days) - 10000;
        $members = $schedule->members;

        return [
            'user' => $members->isEmpty() ? null : $members->values()->get((($turn % $members->count()) + $members->count()) % $members->count()),
            'starts' => $start->utc(),
            'ends' => $start->addDays($days)->utc(),
        ];
    }

    /**
     * Get the next few turns from now, for the schedule's page.
     *
     * @param  OnCallSchedule  $schedule
     * @param  int  $count
     * @param  CarbonImmutable|null  $from
     * @return list<array{user: User|null, starts: CarbonImmutable, ends: CarbonImmutable}>
     */
    public function upcoming(OnCallSchedule $schedule, int $count = 4, ?CarbonImmutable $from = null): array
    {
        $shifts = [];
        $at = $from ?? CarbonImmutable::now('UTC');
        for ($i = 0; $i < $count; $i++) {
            $shift = $this->shift($schedule, $at);
            $shifts[] = $shift;
            $at = $shift['ends']->addSecond();
        }

        return $shifts;
    }
}
