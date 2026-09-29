<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

/** Finds which country an IP address is in, for Analytics' countries report. */
interface CountryLookup
{
    /**
     * Get the ISO 3166 alpha-2 code of the country the address is in, or null when it isn't known.
     *
     * @param  string|null  $ip
     * @return string|null
     */
    public function country(?string $ip): ?string;
}
