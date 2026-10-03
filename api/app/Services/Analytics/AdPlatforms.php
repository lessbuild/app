<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\AdPlatform;
use InvalidArgumentException;

/** Finds the ad platform client for a platform key (google or meta). */
class AdPlatforms
{
    /**
     * Get the client for a platform.
     *
     * @param  string  $platform
     * @return AdPlatform
     */
    public function for(string $platform): AdPlatform
    {
        return match ($platform) {
            'google' => app(GoogleAds::class),
            'meta' => app(MetaAds::class),
            default => throw new InvalidArgumentException("Unknown ad platform {$platform}."),
        };
    }

    /**
     * Get the platforms set up on this installation.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        return array_values(array_filter(['google', 'meta'], fn (string $platform): bool => $this->for($platform)->configured()));
    }
}
