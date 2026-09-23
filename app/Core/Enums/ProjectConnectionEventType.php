<?php

namespace App\Core\Enums;

enum ProjectConnectionEventType: string
{
    case Created = 'created';
    case Reconnected = 'reconnected';
    case Disconnected = 'disconnected';
    case AutomationPaused = 'automation_paused';
    case AutomationResumed = 'automation_resumed';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Created'),
            self::Reconnected => __('Reconnected'),
            self::Disconnected => __('Disconnected'),
            self::AutomationPaused => __('Automation paused'),
            self::AutomationResumed => __('Automation resumed'),
        };
    }
}
