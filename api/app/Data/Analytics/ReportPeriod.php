<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Carbon\CarbonImmutable;

/**
 * The dates an analytics report covers, in the site's timezone, and the period it's compared with: the one just
 * before, the same dates a year earlier, or none.
 */
final readonly class ReportPeriod
{
    /**
     * The ways a report can be compared.
     *
     * @var array<string, string>
     */
    public const COMPARISONS = ['previous' => 'Previous period', 'year' => 'Same period last year', 'none' => 'No comparison'];

    /**
     * The longest custom range, in days.
     *
     * @var int
     */
    public const MAX_DAYS = 395;

    /**
     * Create a new ReportPeriod instance.
     *
     * @param  CarbonImmutable  $start  The first moment counted, in the site's timezone.
     * @param  CarbonImmutable  $end  The last moment counted.
     * @param  CarbonImmutable|null  $previousStart  The start of the comparison period; null when not comparing.
     * @param  CarbonImmutable|null  $previousEnd  The end of the comparison period.
     * @param  int  $days  How many calendar days the report covers.
     * @param  string  $compare  one of COMPARISONS' keys
     * @param  bool  $custom  Whether the dates were chosen by hand rather than a preset.
     */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?CarbonImmutable $previousStart,
        public ?CarbonImmutable $previousEnd,
        public int $days,
        public string $compare = 'previous',
        public bool $custom = false,
    ) {}

    /**
     * Build a preset period: today (`$days` = 1, compared up to the same time) or the last `$days` days (clamped to
     * 7–395) ending today.
     *
     * @param  string  $timezone
     * @param  int  $days
     * @param  string  $compare
     * @return self
     */
    public static function lastDays(string $timezone, int $days, string $compare = 'previous'): self
    {
        $days = $days === 1 ? 1 : min(max($days, 7), self::MAX_DAYS);
        $end = CarbonImmutable::now($timezone)->endOfDay();

        return self::make($end->subDays($days - 1)->startOfDay(), $end, $compare, false);
    }

    /**
     * Build a custom period from two dates (Y-m-d) in the site's timezone. The end can't be after today, the range is
     * at most 395 days, and dates in the wrong order are swapped. Null when either date doesn't parse.
     *
     * @param  string  $timezone
     * @param  string  $from
     * @param  string  $to
     * @param  string  $compare
     * @return self|null
     */
    public static function between(string $timezone, string $from, string $to, string $compare = 'previous'): ?self
    {
        $parse = fn (string $date): ?CarbonImmutable => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
            ? (CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone) ?: null) : null;
        $start = $parse($from);
        $end = $parse($to);
        if ($start === null || $end === null) {
            return null;
        }
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }
        $today = CarbonImmutable::now($timezone)->endOfDay();
        $end = $end->endOfDay()->min($today);
        $start = $start->startOfDay()->min($end->startOfDay())->max($end->subDays(self::MAX_DAYS - 1)->startOfDay());

        return self::make($start, $end, $compare, true);
    }

    /**
     * Work out the comparison period for a range.
     *
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  string  $compare
     * @param  bool  $custom
     * @return self
     */
    private static function make(CarbonImmutable $start, CarbonImmutable $end, string $compare, bool $custom): self
    {
        $compare = array_key_exists($compare, self::COMPARISONS) ? $compare : 'previous';
        $days = (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1;
        // Today so far is compared with the same stretch of the earlier day, not all of it.
        $upTo = $end->isToday() ? CarbonImmutable::now($start->getTimezone()) : $end;
        [$previousStart, $previousEnd] = match ($compare) {
            'none' => [null, null],
            'year' => [$start->subYearNoOverflow(), $upTo->subYearNoOverflow()],
            default => $days === 1 ? [$start->subDay(), $upTo->subDay()] : [$start->subDays($days), $start->subSecond()],
        };

        return new self($start, $end, $previousStart, $previousEnd, $days, $compare, $custom);
    }

    /**
     * Determine whether the report is broken down by hour (a single day) rather than by day.
     *
     * @return bool
     */
    public function hourly(): bool
    {
        return $this->days === 1;
    }

    /**
     * Get the query parameters that reproduce this period in a link.
     *
     * @return array<string, string|int>
     */
    public function query(): array
    {
        $query = $this->custom ? ['from' => $this->start->toDateString(), 'to' => $this->end->toDateString()] : ['days' => $this->days];

        return $this->compare === 'previous' ? $query : [...$query, 'compare' => $this->compare];
    }
}
