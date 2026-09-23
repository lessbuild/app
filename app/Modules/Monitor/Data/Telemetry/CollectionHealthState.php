<?php

namespace App\Modules\Monitor\Data\Telemetry;

enum CollectionHealthState: string
{
    case Receiving = 'receiving';
    case Stale = 'stale';
    case Awaiting = 'awaiting';
    case Paused = 'paused';
    case NoToken = 'no_token';

    public function label(): string
    {
        return match ($this) {
            self::Receiving => 'Receiving',
            self::Stale => 'Stale',
            self::Awaiting => 'Awaiting first event',
            self::Paused => 'Paused',
            self::NoToken => 'No active token',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Receiving => 'green',
            self::Stale, self::Paused => 'amber',
            self::NoToken => 'red',
            self::Awaiting => 'slate',
        };
    }
}
