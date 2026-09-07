<?php

namespace App\Support;

final class DateRange
{
    /** @return array{0: ?string, 1: ?string} */
    public static function normalize(string $from, string $to): array
    {
        $from = self::date($from);
        $to = self::date($to);

        if ($from !== null && $to !== null && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Accept only an exact calendar date in YYYY-MM-DD form.
     *
     * @param  string  $value  The date string to parse without accepting calendar overflow.
     * @return string|null The original valid date, or null when parsing or exact round-trip validation fails.
     */
    private static function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
