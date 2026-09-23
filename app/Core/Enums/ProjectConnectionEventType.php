<?php

namespace App\Core\Enums;

enum ProjectConnectionEventType: string
{
    case Created = 'created';
    case Reconnected = 'reconnected';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Created'),
            self::Reconnected => __('Reconnected'),
            self::Disconnected => __('Disconnected'),
        };
    }
}
