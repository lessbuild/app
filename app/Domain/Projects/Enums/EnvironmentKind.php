<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

enum EnvironmentKind: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';
    case Preview = 'preview';

    public function label(): string
    {
        return match ($this) {
            self::Production => __('Production'),
            self::Staging => __('Staging'),
            self::Development => __('Development'),
            self::Preview => __('Preview'),
        };
    }
}
