<?php

declare(strict_types=1);

namespace App\Enums;

enum EnvironmentKind: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';
    case Preview = 'preview';

    /**
     * Get the kind's name as shown on environment lists and forms.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Production => __('Production'),
            self::Staging => __('Staging'),
            self::Development => __('Development'),
            self::Preview => __('Preview'),
        };
    }

    /**
     * Get where the environment sits on the way to production: builds are promoted to a higher rank only.
     *
     * @return int
     */
    public function rank(): int
    {
        return match ($this) {
            self::Preview => 0,
            self::Development => 1,
            self::Staging => 2,
            self::Production => 3,
        };
    }
}
