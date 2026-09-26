<?php

namespace App\Modules\Monitor\Data\Telemetry;

enum IngestStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::Failed => 'red',
            self::Retrying => 'amber',
            default => 'slate',
        };
    }
}
