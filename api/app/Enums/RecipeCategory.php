<?php

declare(strict_types=1);

namespace App\Enums;

enum RecipeCategory: string
{
    case Security = 'security';
    case Runtime = 'runtime';
    case Database = 'database';
    case Monitoring = 'monitoring';
    case Deployment = 'deployment';
    case Utilities = 'utilities';

    /**
     * Get the category's name as shown in the gallery and on forms.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Security => __('Security'),
            self::Runtime => __('Runtimes'),
            self::Database => __('Databases'),
            self::Monitoring => __('Monitoring'),
            self::Deployment => __('Deployment'),
            self::Utilities => __('Utilities'),
        };
    }
}
