<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\SearchConsole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** Google Search Console over its REST API, with the platform's OAuth client (services.google_search_console). */
final class GoogleSearchConsole implements SearchConsole
{
    /**
     * The read-only Search Console scope.
     *
     * @var string
     */
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Create a new GoogleSearchConsole instance.
     *
     * @param  GoogleOAuth  $google  The platform's Google OAuth client.
     */
    public function __construct(private readonly GoogleOAuth $google = new GoogleOAuth) {}

    /**
     * Determine whether the platform has Google OAuth credentials for Search Console.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->google->configured();
    }

    /**
     * Get the Google sign-in URL that asks for read-only Search Console access, offline so a refresh token comes back.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return $this->google->authorizationUrl(self::SCOPE, route('analytics.search-console.callback'), $state);
    }

    /**
     * Swap the code Google sent back for a refresh token.
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string
    {
        return $this->google->exchange($code, route('analytics.search-console.callback'));
    }

    /**
     * List the Search Console properties the Google account can read.
     *
     * @param  string  $refreshToken
     * @return list<string>
     */
    public function properties(string $refreshToken): array
    {
        $response = $this->api($refreshToken)->get('https://www.googleapis.com/webmasters/v3/sites');
        $this->check($response);
        $properties = [];
        foreach ((array) $response->json('siteEntry', []) as $entry) {
            if (is_array($entry) && is_string($entry['siteUrl'] ?? null) && ($entry['permissionLevel'] ?? '') !== 'siteUnverifiedUser') {
                $properties[] = $entry['siteUrl'];
            }
        }
        sort($properties);

        return $properties;
    }

    /**
     * Get the top ten search queries for a property over a period.
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
        $body = ['startDate' => $from->toDateString(), 'endDate' => $until->toDateString(), 'dimensions' => ['query'], 'rowLimit' => 10];
        if ($pageContains !== null && $pageContains !== '') {
            $body['dimensionFilterGroups'] = [['filters' => [['dimension' => 'page', 'operator' => 'contains', 'expression' => $pageContains]]]];
        }
        $response = $this->api($refreshToken)->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($property).'/searchAnalytics/query', $body);
        $this->check($response);
        $rows = [];
        foreach ((array) $response->json('rows', []) as $row) {
            if (is_array($row) && is_array($row['keys'] ?? null) && is_string($row['keys'][0] ?? null)) {
                $rows[] = [
                    'query' => $row['keys'][0],
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 1),
                    'position' => round((float) ($row['position'] ?? 0), 1),
                ];
            }
        }

        return $rows;
    }

    /**
     * Start a request to Google's API with a fresh access token.
     *
     * @param  string  $refreshToken
     * @return PendingRequest
     */
    private function api(string $refreshToken): PendingRequest
    {
        return $this->google->client($refreshToken, __('Google no longer accepts this connection. Connect Search Console again.'));
    }

    /**
     * Turn an error response from Google into an exception with its message.
     *
     * @param  Response  $response
     * @return void
     */
    private function check(Response $response): void
    {
        $this->google->check($response);
    }
}
