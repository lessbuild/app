<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\Analytics\SearchConsole;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Search Console without Google: one code, a fixed list of properties, and canned search terms. */
final class FakeSearchConsole implements SearchConsole
{
    /**
     * The properties the fake Google account can read.
     *
     * @var list<string>
     */
    public array $properties = ['https://blog.example/', 'sc-domain:shop.example'];

    /**
     * The queries asked for, as [property, from, until, page filter].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public array $asked = [];

    /**
     * Determine whether the platform has Google OAuth credentials: always, for the fake.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * Get a pretend Google sign-in URL carrying the state.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.test/auth?state='.$state;
    }

    /**
     * Accept only the code "good-code".
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string
    {
        return $code === 'good-code' ? 'refresh-token' : throw new RuntimeException('Google answered: invalid_grant');
    }

    /**
     * List the fake account's properties.
     *
     * @param  string  $refreshToken
     * @return list<string>
     */
    public function properties(string $refreshToken): array
    {
        return $this->properties;
    }

    /**
     * Return two canned search terms and remember what was asked.
     *
     * @param  string  $refreshToken
     * @param  string  $property
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  string|null  $pageContains
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function topQueries(string $refreshToken, string $property, CarbonImmutable $from, CarbonImmutable $until, ?string $pageContains = null): array
    {
        $this->asked[] = [$property, $from->toDateString(), $until->toDateString(), $pageContains];

        return [['query' => 'buy shoes online', 'clicks' => 42, 'impressions' => 900, 'ctr' => 4.7, 'position' => 3.2], ['query' => 'shoe shop', 'clicks' => 7, 'impressions' => 300, 'ctr' => 2.3, 'position' => 8.9]];
    }
}
