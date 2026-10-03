<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

use Carbon\CarbonImmutable;
use RuntimeException;

/** Google Search Console, read-only: which properties a Google account can see and what people searched for. */
interface SearchConsole
{
    /**
     * Determine whether the platform has Google OAuth credentials for Search Console.
     *
     * @return bool
     */
    public function configured(): bool;

    /**
     * Get the Google sign-in URL that asks for read-only Search Console access.
     *
     * @param  string  $state  echoed back to the callback, to tie it to this request
     * @return string
     */
    public function authorizationUrl(string $state): string;

    /**
     * Swap the code Google sent back for a long-lived refresh token.
     *
     * @param  string  $code
     * @return string
     *
     * @throws RuntimeException when Google refuses
     */
    public function exchange(string $code): string;

    /**
     * List the Search Console properties the Google account can read, such as "sc-domain:example.com".
     *
     * @param  string  $refreshToken
     * @return list<string>
     *
     * @throws RuntimeException when Google refuses
     */
    public function properties(string $refreshToken): array;

    /**
     * Get the top search queries for a property over a period, optionally only for pages whose URL contains a path.
     *
     * @param  string  $refreshToken
     * @param  string  $property
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  string|null  $pageContains
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>
     *
     * @throws RuntimeException when Google refuses
     */
    public function topQueries(string $refreshToken, string $property, CarbonImmutable $from, CarbonImmutable $until, ?string $pageContains = null): array;
}
