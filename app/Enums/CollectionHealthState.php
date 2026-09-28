<?php

declare(strict_types=1);

namespace App\Enums;

enum CollectionHealthState: string
{
    case Receiving = 'receiving';
    case Stale = 'stale';
    case Awaiting = 'awaiting';
    case NoToken = 'no_token';

    /**
     * Get the state's name as the telemetry setup page shows it.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Receiving => __('Receiving'),
            self::Stale => __('Stale'),
            self::Awaiting => __('Waiting for the first event'),
            self::NoToken => __('No ingest key'),
        };
    }

    /**
     * Get the badge colour for the state: green while events arrive, amber when they've gone quiet, red when there's
     * no token to send with.
     *
     * @return string
     */
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
