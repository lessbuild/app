<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A change that clashes with the record's current state (someone else changed it, or it's in the wrong state); rendered as 409. */
final class StateConflict extends DomainException
{
    /** The record changed since the form was opened. */
    public static function unlessVersion(int $current, int $expected, string $message): void
    {
        if ($current !== $expected) {
            throw new self($message);
        }
    }

    public static function unless(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new self($message);
        }
    }
}
