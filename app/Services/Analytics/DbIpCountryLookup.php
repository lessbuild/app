<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\CountryLookup;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Looks countries up in DB-IP's free IP-to-country database (MaxMind format), kept up to date by
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
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        $reader = $this->reader();
        if ($reader === false) {
            return null;
        }
        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }
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
