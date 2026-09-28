<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A change that clashes with the record's current state (someone else changed it, or it's in the wrong state); rendered as 409. */
final class StateConflict extends DomainException
{
    /**
     * Throws a conflict (HTTP 409) when the record's version no longer matches the one the form was opened with, so a
     * stale edit can't silently overwrite someone else's change.
     *
     * @param  int  $current
     * @param  int  $expected
     * @param  string  $message
     * @return void
     */
    public static function unlessVersion(int $current, int $expected, string $message): void
    {
        if ($current !== $expected) {
            throw new self($message);
        }
    }

    /**
     * Throws a conflict (HTTP 409) with `$message` when `$condition` is false. Used for actions that only make sense in
     * some states, such as cancelling a deploy that already finished.
     *
     * @param  bool  $condition
     * @param  string  $message
     * @return void
     */
    public static function unless(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new self($message);
        }
    }
}
