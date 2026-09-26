<?php

namespace App\Modules\Monitor\Data\Telemetry;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class OtlpTimestamp
{
    private function __construct(
        public string $unixNano,
        public int $seconds,
        public int $nanoseconds,
    ) {}

    public static function isValid(mixed $value): bool
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[0-9]{1,20}\z/D', (string) $value) !== 1) {
            return false;
        }

        $canonical = ltrim((string) $value, '0') ?: '0';

        return strlen($canonical) < 20 || strcmp($canonical, '18446744073709551615') <= 0;
    }

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

    public function iso8601(): string
    {
        return CarbonImmutable::createFromTimestampUTC($this->seconds)
            ->addMicroseconds(intdiv($this->nanoseconds, 1_000))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    public function millisecondsUntil(self $end): float
    {
        return round(($end->seconds - $this->seconds) * 1_000
            + ($end->nanoseconds - $this->nanoseconds) / 1_000_000, 6);
    }
}
