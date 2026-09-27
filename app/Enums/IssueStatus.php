<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Snoozed = 'snoozed';
    case Ignored = 'ignored';

    public function label(): string
    {
        return __(ucfirst($this->value));
    }

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
