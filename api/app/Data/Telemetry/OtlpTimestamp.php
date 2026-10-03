<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class OtlpTimestamp
{
    /**
     * Create a new OtlpTimestamp instance.
     *
     * Use `fromUnixNano()`, which validates the value first.
     *
     * @param  string  $unixNano  The timestamp in nanoseconds as a canonical decimal string. OTLP timestamps are
     *                            unsigned 64-bit, beyond what a PHP int holds, so they stay strings.
     * @param  int  $seconds  The whole seconds since the Unix epoch.
     * @param  int  $nanoseconds  The remaining nanoseconds (0 to 999,999,999).
     */
    private function __construct(
        public string $unixNano,
        public int $seconds,
        public int $nanoseconds,
    ) {}

    /**
     * Determine whether a value is an unsigned 64-bit integer, given as an int or a string of digits.
     *
     * @param  mixed  $value
     * @return bool
     */
    public static function isValid(mixed $value): bool
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[0-9]{1,20}\z/D', (string) $value) !== 1) {
            return false;
        }

        $canonical = ltrim((string) $value, '0') ?: '0';

        return strlen($canonical) < 20 || strcmp($canonical, '18446744073709551615') <= 0;
    }

    /**
     * Parse an OTLP nanosecond timestamp; null stays null, and anything that isn't an unsigned 64-bit integer throws.
     *
     * @param  mixed  $value
     * @return OtlpTimestamp|null
     */
    public static function fromUnixNano(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if (! self::isValid($value)) {
            throw new InvalidArgumentException('Invalid unsigned nanosecond timestamp.');
        }

        $canonical = ltrim((string) $value, '0') ?: '0';

        $padded = str_pad($canonical, 10, '0', STR_PAD_LEFT);

        return new self($canonical, (int) substr($padded, 0, -9), (int) substr($padded, -9));
    }

    /**
     * Format the timestamp in UTC with microsecond precision, which is as fine as Carbon goes.
     *
     * @return string
     */
    public function iso8601(): string
    {
        return CarbonImmutable::createFromTimestampUTC($this->seconds)
            ->addMicroseconds(intdiv($this->nanoseconds, 1_000))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Measure the time from this timestamp to `$end` in milliseconds, computed from the split seconds and nanoseconds
     * so no precision is lost to floats.
     *
     * @param  OtlpTimestamp  $end
     * @return float
     */
    public function millisecondsUntil(self $end): float
    {
        return round(($end->seconds - $this->seconds) * 1_000
            + ($end->nanoseconds - $this->nanoseconds) / 1_000_000, 6);
    }
}
