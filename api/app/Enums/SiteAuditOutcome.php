<?php

declare(strict_types=1);

namespace App\Enums;

/** How a simulated visitor's journey ended. */
enum SiteAuditOutcome: string
{
    case Succeeded = 'succeeded';
    case Struggled = 'struggled';
    case Failed = 'failed';

    /**
     * Get the outcome as people read it.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Succeeded => __('Succeeded'),
            self::Struggled => __('Succeeded with difficulty'),
            self::Failed => __('Gave up'),
        };
    }
}
