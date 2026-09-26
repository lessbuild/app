<?php

namespace App\Core\Data\Projects;

enum ProjectResourceDestinationState: string
{
    case Available = 'available';
    case AccessChanged = 'access_changed';
    case Missing = 'missing';
    case Stale = 'stale';
    case Unavailable = 'unavailable';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::AccessChanged => __('Access changed'),
            self::Missing => __('Source missing'),
            self::Stale => __('Archived mapping'),
            self::Unavailable => __('Product unavailable'),
            self::Unsupported => __('No direct link'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Available => __('Access to this resource is confirmed.'),
            self::AccessChanged => __('Access to this mapped resource could not be confirmed.'),
            self::Missing => __('The mapped resource no longer exists in its app.'),
            self::Stale => __('This resource mapping or its source is archived.'),
            self::Unavailable => __('The app is unavailable. Details stay hidden until access can be confirmed.'),
            self::Unsupported => __('No direct link is available for this resource.'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Missing => 'danger',
            self::AccessChanged, self::Stale => 'warning',
            self::Unavailable, self::Unsupported => 'neutral',
        };
    }
}
