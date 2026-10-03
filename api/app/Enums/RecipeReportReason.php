<?php

declare(strict_types=1);

namespace App\Enums;

enum RecipeReportReason: string
{
    case Malicious = 'malicious';
    case Broken = 'broken';
    case Spam = 'spam';
    case Other = 'other';

    /**
     * Get the reason as shown on the report form and to the publisher.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Malicious => __('Harmful or malicious'),
            self::Broken => __('Doesn’t work'),
            self::Spam => __('Spam or advertising'),
            self::Other => __('Something else'),
        };
    }
}
