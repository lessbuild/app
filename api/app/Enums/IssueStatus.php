<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Snoozed = 'snoozed';
    case Ignored = 'ignored';

    /**
     * Get the status as shown on the issues list and issue page.
     *
     * @return string
     */
    public function label(): string
    {
        return __(ucfirst($this->value));
    }

    /**
     * Get the badge colour for the status.
     *
     * @return string
     */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Resolved => 'success',
            self::Snoozed => 'warning',
            self::Ignored => 'neutral',
        };
    }
}
