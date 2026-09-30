<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

/** Finds where an IP address is, for Analytics' countries, regions and cities reports. */
interface CountryLookup
{
    /**
     * Get the ISO 3166 alpha-2 code of the country the address is in, or null when it isn't known.
     *
     * @param  string|null  $ip
     * @return string|null
     */
    public function country(?string $ip): ?string;

    /**
     * Get the country, region and city the address is in. Region and city are only known with the city edition of
     * the database; any part can be null.
     *
     * @param  string|null  $ip
     * @return array{country: string|null, region: string|null, city: string|null}
     */
    public function location(?string $ip): array;
}
