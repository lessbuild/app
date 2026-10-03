<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;

/** Five-field cron expressions read in an IANA time zone, as schedules store them. */
final class Cron
{
    /**
     * Determine whether an expression has five fields cron accepts and the time zone is a known IANA zone.
     *
     * @param  string  $expression
     * @param  string  $timezone
     * @return bool
     */
    public static function valid(string $expression, string $timezone): bool
    {
        $expression = trim($expression);

        return strlen($expression) <= 100 && count(preg_split('/\s+/', $expression) ?: []) === 5
            && CronExpression::isValidExpression($expression) && in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * Determine whether the expression is due in the minute of a moment, read in the time zone. An invalid schedule is
     * never due.
     *
     * @param  string  $expression
     * @param  string  $timezone
     * @param  CarbonInterface  $at
     * @return bool
     */
    public static function isDue(string $expression, string $timezone, CarbonInterface $at): bool
    {
        return self::valid($expression, $timezone) && (new CronExpression(trim($expression)))->isDue($at->toDateTimeImmutable(), $timezone);
    }

    /**
     * Get the next time the expression is due after a moment, in UTC, or null for an invalid schedule.
     *
     * @param  string  $expression
     * @param  string  $timezone
     * @param  CarbonInterface  $after
     * @return CarbonImmutable|null
     */
    public static function next(string $expression, string $timezone, CarbonInterface $after): ?CarbonImmutable
    {
        if (! self::valid($expression, $timezone)) {
            return null;
        }
        $cron = (new CronExpression(trim($expression)))->setMaxIterationCount(2400);

        return CarbonImmutable::instance($cron->getNextRunDate($after->toDateTimeImmutable(), 0, false, $timezone))->utc();
    }
}
