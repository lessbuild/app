<?php

namespace App\Core\Data\Projects;

enum WorkspaceProjectOperationalState: string
{
    case All = 'all';
    case Attention = 'attention';
    case Setup = 'setup';
    case Unavailable = 'unavailable';
    case Current = 'current';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All states'),
            self::Attention => __('Needs attention'),
            self::Setup => __('Setup needed'),
            self::Unavailable => __('Unavailable'),
            self::Current => __('Current'),
        };
    }
}
