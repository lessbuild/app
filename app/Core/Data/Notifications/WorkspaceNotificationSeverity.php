<?php

namespace App\Core\Data\Notifications;

enum WorkspaceNotificationSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Information = 'information';

    public function label(): string
    {
        return match ($this) {
            self::Critical => __('Critical'),
            self::Warning => __('Warning'),
            self::Information => __('Information'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::Warning => 'warning',
            self::Information => 'info',
        };
    }
}
