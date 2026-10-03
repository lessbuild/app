<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

use Carbon\CarbonImmutable;

/** Reads a Google Analytics 4 property's daily totals, for importing a site's history. */
interface GoogleAnalyticsData
{
    /**
     * Determine whether the platform has Google OAuth credentials.
     *
     * @return bool
     */
    public function configured(): bool;

    /**
     * Get the Google sign-in URL asking for read-only Analytics access.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string;

    /**
     * Swap the code Google sent back for a refresh token.
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string;

    /**
     * List the GA4 properties the Google account can read.
     *
     * @param  string  $refreshToken
     * @return list<array{id: string, name: string}>
     */
    public function properties(string $refreshToken): array;

    /**
     * Get a property's totals per day, and per value of one GA dimension when given (null for the whole site).
     *
     * @param  string  $refreshToken
     * @param  string  $property  the numeric property ID
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  string|null  $dimension  a GA4 dimension name, such as pagePath
     * @return list<array{date: string, value: string|null, pageviews: int, visits: int, visitors: int, bounces: int, duration: int}>
     */
    public function daily(string $refreshToken, string $property, CarbonImmutable $from, CarbonImmutable $until, ?string $dimension): array;
}
