<?php

declare(strict_types=1);

namespace App\Enums;

enum CollectionHealthState: string
{
    case Receiving = 'receiving';
    case Stale = 'stale';
    case Awaiting = 'awaiting';
    case NoToken = 'no_token';

    public function label(): string
    {
        return match ($this) {
            self::Receiving => __('Receiving'),
            self::Stale => __('Stale'),
            self::Awaiting => __('Waiting for the first event'),
            self::NoToken => __('No ingest key'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Receiving => 'success',
            self::Stale => 'warning',
            self::NoToken => 'danger',
            self::Awaiting => 'neutral',
        };
    }
}
