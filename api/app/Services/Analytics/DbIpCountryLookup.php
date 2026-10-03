<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\CountryLookup;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Looks locations up in DB-IP's free IP-to-country or IP-to-city database (MaxMind format), kept up to date by
 * `analytics:update-geoip`. Without the database every lookup answers null, and collection carries on.
 */
final class DbIpCountryLookup implements CountryLookup
{
    /**
     * The open database, or false once opening it failed.
     *
     * @var Reader|false|null
     */
    private Reader|false|null $reader = null;

    /**
     * Get the ISO 3166 alpha-2 code of the country the address is in, or null when it isn't known.
     *
     * @param  string|null  $ip
     * @return string|null
     */
    public function country(?string $ip): ?string
    {
        return $this->countryOf($this->record($ip));
    }

    /**
     * Get the country, region and city the address is in, reading the region and city from the city edition of
     * DB-IP's database when that's the one installed.
     *
     * @param  string|null  $ip
     * @return array{country: string|null, region: string|null, city: string|null}
     */
    public function location(?string $ip): array
    {
        $record = $this->record($ip);
        $name = fn (mixed $place): ?string => is_array($place) && is_array($place['names'] ?? null) && is_string($place['names']['en'] ?? null)
            ? mb_substr($place['names']['en'], 0, 100) : null;

        return [
            'country' => $this->countryOf($record),
            'region' => is_array($record) && is_array($record['subdivisions'] ?? null) ? $name($record['subdivisions'][0] ?? null) : null,
            'city' => is_array($record) ? $name($record['city'] ?? null) : null,
        ];
    }

    /**
     * Read the database's record for a public address; null for private or invalid addresses, unknown ones, or when
     * the database isn't installed.
     *
     * @param  string|null  $ip
     * @return mixed
     */
    private function record(?string $ip): mixed
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        $reader = $this->reader();
        if ($reader === false) {
            return null;
        }
        try {
            return $reader->get($ip);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the ISO country code from a record.
     *
     * @param  mixed  $record
     * @return string|null
     */
    private function countryOf(mixed $record): ?string
    {
        $code = is_array($record) && is_array($record['country'] ?? null) ? ($record['country']['iso_code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * Open the database once per process.
     *
     * @return Reader|false
     */
    private function reader(): Reader|false
    {
        if ($this->reader === null) {
            $path = (string) config('analytics.geoip_database');
            try {
                $this->reader = is_file($path) ? new Reader($path) : false;
            } catch (Throwable) {
                $this->reader = false;
            }
        }

        return $this->reader;
    }
}
