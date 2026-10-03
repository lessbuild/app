<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/** How often an audit runs by itself. */
enum SiteAuditSchedule: string
{
    case None = 'none';
    case Monthly = 'monthly';
    case Weekly = 'weekly';

    /**
     * Get the schedule as people read it.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::None => __('Only when I run it'),
            self::Monthly => __('Every month'),
            self::Weekly => __('Every week'),
        };
    }

    /**
     * Get when the next scheduled run is due after a given time, or null when the audit isn't scheduled.
     *
     * @param  Carbon  $after
     * @return Carbon|null
     */
    public function nextRunAfter(Carbon $after): ?Carbon
    {
        return match ($this) {
            self::None => null,
            self::Monthly => $after->copy()->addMonthNoOverflow(),
            self::Weekly => $after->copy()->addWeek(),
        };
    }

    /**
     * Get the entitlement flag a plan needs for this schedule, or null when every plan has it.
     *
     * @return string|null
     */
    public function flag(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Monthly => 'audit.schedule.monthly',
            self::Weekly => 'audit.schedule.weekly',
        };
    }
}
