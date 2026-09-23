<?php

namespace App\Modules\Monitor\Data\Telemetry;

enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Snoozed = 'snoozed';
    case Ignored = 'ignored';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'red',
            self::Resolved => 'green',
            self::Snoozed => 'amber',
            self::Ignored => 'slate',
        };
    }
}
