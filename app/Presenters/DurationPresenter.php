<?php

namespace App\Presenters;

use InvalidArgumentException;

final class DurationPresenter
{
    /**
     * Format elapsed seconds into a compact label for completed work.
     *
     * @param  int  $seconds  A nonnegative elapsed duration, including zero.
     * @return string A label such as "1h 2m 3s" or "0s".
     *
     * @throws InvalidArgumentException When the supplied duration is negative.
     */
    public static function format(int $seconds): string
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A duration cannot be negative.');
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($remainingSeconds > 0 || $parts === []) {
            $parts[] = "{$remainingSeconds}s";
        }

        return implode(' ', $parts);
    }
}
