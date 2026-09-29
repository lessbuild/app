<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\SearchConsole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
     * Determine whether the platform has Google OAuth credentials for Search Console.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('services.google_search_console.client_id')) && filled(config('services.google_search_console.client_secret'));
    }

    /**
     * Get the Google sign-in URL that asks for read-only Search Console access, offline so a refresh token comes back.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_search_console.client_id'),
            'redirect_uri' => route('analytics.search-console.callback'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Swap the code Google sent back for a refresh token.
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string
    {
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => route('analytics.search-console.callback')]);
        $refresh = $response->json('refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException(__('Google didn’t grant offline access. Try connecting again.'));
        }

        return $refresh;
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
     * Start a request to Google's API with a fresh access token (kept for 50 minutes per refresh token).
     *
     * @param  string  $refreshToken
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function api(string $refreshToken): \Illuminate\Http\Client\PendingRequest
    {
        $access = Cache::remember('search-console.access.'.hash('sha256', $refreshToken), 3000, function () use ($refreshToken): string {
            $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken])->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException(__('Google no longer accepts this connection. Connect Search Console again.'));
            }

            return $token;
        });

        return Http::withToken($access)->acceptJson()->timeout(15);
    }

    /**
     * Call Google's token endpoint with the platform's client credentials.
     *
     * @param  array<string, string>  $parameters
     * @return Response
     */
    private function token(array $parameters): Response
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_search_console.client_id'),
            'client_secret' => config('services.google_search_console.client_secret'),
            ...$parameters,
        ]);
        $this->check($response);

        return $response;
    }

    /**
     * Turn an error response from Google into an exception with its message.
     *
     * @param  Response  $response
     * @return void
     */
    private function check(Response $response): void
    {
        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error');
            throw new RuntimeException(__('Google answered: :message', ['message' => is_string($message) ? $message : 'HTTP '.$response->status()]));
        }
    }
}
