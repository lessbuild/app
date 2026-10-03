<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use Carbon\CarbonImmutable;

/** Google Analytics 4 over its Admin and Data APIs, with the platform's Google OAuth client. */
final class GoogleAnalytics implements GoogleAnalyticsData
{
    /**
     * The read-only Analytics scope.
     *
     * @var string
     */
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    /**
     * How many rows to ask for per request.
     *
     * @var int
     */
    private const PAGE = 100_000;

    /**
     * Create a new GoogleAnalytics instance.
     *
     * @param  GoogleOAuth  $google  The platform's Google OAuth client.
     */
    public function __construct(private readonly GoogleOAuth $google = new GoogleOAuth) {}

    /**
     * Determine whether the platform has Google OAuth credentials.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->google->configured();
    }

    /**
     * Get the Google sign-in URL asking for read-only Analytics access.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return $this->google->authorizationUrl(self::SCOPE, route('analytics.google-analytics.callback'), $state);
    }

    /**
     * Swap the code Google sent back for a refresh token.
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string
    {
        return $this->google->exchange($code, route('analytics.google-analytics.callback'));
    }

    /**
     * List the GA4 properties the Google account can read, by name.
     *
     * @param  string  $refreshToken
     * @return list<array{id: string, name: string}>
     */
    public function properties(string $refreshToken): array
    {
        $response = $this->client($refreshToken)->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', ['pageSize' => 200]);
        $this->google->check($response);
        $properties = [];
        foreach ((array) $response->json('accountSummaries', []) as $account) {
            foreach (is_array($account) ? (array) ($account['propertySummaries'] ?? []) : [] as $property) {
                if (is_array($property) && is_string($property['property'] ?? null) && preg_match('~^properties/(\d+)$~', $property['property'], $match) === 1) {
                    $properties[] = ['id' => $match[1], 'name' => (is_string($account['displayName'] ?? null) ? $account['displayName'].' · ' : '').($property['displayName'] ?? $match[1])];
                }
            }
        }
        usort($properties, fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $properties;
    }

    /**
     * Get a property's pageviews, sessions, users, bounces (sessions that weren't engaged) and engagement time per day,
     * and per value of one dimension when given, paging through the results.
     *
     * @param  string  $refreshToken
     * @param  string  $property
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  string|null  $dimension
     * @return list<array{date: string, value: string|null, pageviews: int, visits: int, visitors: int, bounces: int, duration: int}>
     */
    public function daily(string $refreshToken, string $property, CarbonImmutable $from, CarbonImmutable $until, ?string $dimension): array
    {
        $rows = [];
        $offset = 0;
        do {
            $response = $this->client($refreshToken)->post('https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($property).':runReport', [
                'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $until->toDateString()]],
                'dimensions' => $dimension === null ? [['name' => 'date']] : [['name' => 'date'], ['name' => $dimension]],
                'metrics' => [['name' => 'screenPageViews'], ['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'engagedSessions'], ['name' => 'userEngagementDuration']],
                'limit' => self::PAGE,
                'offset' => $offset,
                'keepEmptyRows' => false,
            ]);
            $this->google->check($response);
            $page = (array) $response->json('rows', []);
            foreach ($page as $row) {
                $dimensions = is_array($row) ? array_column((array) ($row['dimensionValues'] ?? []), 'value') : [];
                $metrics = is_array($row) ? array_map('intval', array_column((array) ($row['metricValues'] ?? []), 'value')) : [];
                $date = CarbonImmutable::createFromFormat('!Ymd', (string) ($dimensions[0] ?? ''));
                if (! $date instanceof CarbonImmutable || count($metrics) < 5) {
                    continue;
                }
                $rows[] = [
                    'date' => $date->toDateString(),
                    'value' => $dimension === null ? null : (string) ($dimensions[1] ?? ''),
                    'pageviews' => $metrics[0],
                    'visits' => $metrics[1],
                    'visitors' => $metrics[2],
                    'bounces' => max(0, $metrics[1] - $metrics[3]),
                    'duration' => $metrics[4],
                ];
            }
            $offset += self::PAGE;
        } while (count($page) === self::PAGE && $offset < (int) $response->json('rowCount', 0));

        return $rows;
    }

    /**
     * Start a request to Google's APIs with a fresh access token.
     *
     * @param  string  $refreshToken
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function client(string $refreshToken): \Illuminate\Http\Client\PendingRequest
    {
        return $this->google->client($refreshToken, __('Google no longer accepts this connection. Connect Google Analytics again.'));
    }
}
