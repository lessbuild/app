<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Carbon\CarbonImmutable;

/** Formats times for comparison with stored telemetry `occurred_at` values. */
final class EventTime
{
    /**
     * The time as a comparison bound for `occurred_at`. Events imported from older systems were stored with whole
     * seconds only, and "12:00:00" sorts before "12:00:00.000000" as text on SQLite, so a whole-second bound is
     * written without a fraction to keep those rows on the right side of it.
     *
     * @param  CarbonImmutable  $time
     * @return string
     */
    public static function boundary(CarbonImmutable $time): string
    {
        return $time->format($time->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }
}
