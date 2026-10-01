<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\AdPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta (Facebook and Instagram) ads spend through the Marketing API, with the platform's Meta app
 * (services.meta_ads) and a long-lived token from Facebook Login with the ads_read permission.
 */
final class MetaAds implements AdPlatform
{
    /** The Graph API version. */
    private const string GRAPH = 'https://graph.facebook.com/v21.0';

    /**
     * Determine whether a Meta app is set up.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('services.meta_ads.app_id')) && filled(config('services.meta_ads.app_secret'));
    }

    /**
     * Get the Facebook Login address asking for ads_read.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return 'https://www.facebook.com/v21.0/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta_ads.app_id'), 'redirect_uri' => route('analytics.ads.callback', 'meta'),
            'state' => $state, 'scope' => 'ads_read', 'response_type' => 'code',
        ]);
    }

    /**
     * Swap Meta's code for a token, then for a long-lived one (about 60 days).
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string
    {
        $short = $this->check(Http::acceptJson()->timeout(15)->get(self::GRAPH.'/oauth/access_token', [
            'client_id' => config('services.meta_ads.app_id'), 'client_secret' => config('services.meta_ads.app_secret'),
            'redirect_uri' => route('analytics.ads.callback', 'meta'), 'code' => $code,
        ]))->json('access_token');
        $long = is_string($short) ? $this->check(Http::acceptJson()->timeout(15)->get(self::GRAPH.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => config('services.meta_ads.app_id'),
            'client_secret' => config('services.meta_ads.app_secret'), 'fb_exchange_token' => $short,
        ]))->json('access_token') : null;
        if (! is_string($long) || $long === '') {
            throw new RuntimeException(__('Meta didn’t grant access to ads reporting. Try connecting again.'));
        }

        return $long;
    }

    /**
     * List the ad accounts the person can read.
     *
     * @param  string  $credential
     * @return list<array{id: string, name: string, currency: string|null}>
     */
    public function accounts(string $credential): array
    {
        $accounts = [];
        $url = self::GRAPH.'/me/adaccounts?'.http_build_query(['fields' => 'account_id,name,currency', 'limit' => 100, 'access_token' => $credential]);
        for ($page = 0; $url !== null && $page < 10; $page++) {
            $response = $this->check(Http::acceptJson()->timeout(20)->get($url));
            foreach ((array) $response->json('data', []) as $account) {
                if (is_array($account) && is_string($account['account_id'] ?? null) && ctype_digit($account['account_id'])) {
                    $accounts[] = ['id' => $account['account_id'], 'name' => is_string($account['name'] ?? null) ? $account['name'] : $account['account_id'], 'currency' => is_string($account['currency'] ?? null) ? $account['currency'] : null];
                }
            }
            $next = $response->json('paging.next');
            $url = is_string($next) && str_starts_with($next, 'https://graph.facebook.com/') ? $next : null;
        }

        return $accounts;
    }

    /**
     * Read an ad account's spend, clicks and impressions per campaign and day.
     *
     * @param  string  $credential
     * @param  string  $accountId
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return list<array{date: string, campaign: string, cost: float, currency: string, clicks: int|null, impressions: int|null}>
     */
    public function spend(string $credential, string $accountId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if (! ctype_digit($accountId)) {
            throw new RuntimeException(__('That isn’t a Meta ad account ID.'));
        }
        $currency = $this->check(Http::acceptJson()->timeout(20)->get(self::GRAPH."/act_{$accountId}", ['fields' => 'currency', 'access_token' => $credential]))->json('currency');
        $rows = [];
        $url = self::GRAPH."/act_{$accountId}/insights?".http_build_query([
            'level' => 'campaign', 'fields' => 'campaign_name,spend,clicks,impressions', 'time_increment' => 1, 'limit' => 500,
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $until->toDateString()]), 'access_token' => $credential,
        ]);
        for ($page = 0; $url !== null && $page < 50; $page++) {
            $response = $this->check(Http::acceptJson()->timeout(30)->get($url));
            foreach ((array) $response->json('data', []) as $row) {
                if (! is_array($row) || ! is_string($row['date_start'] ?? null) || ! is_string($row['campaign_name'] ?? null) || ! is_numeric($row['spend'] ?? null)) {
                    continue;
                }
                $rows[] = [
                    'date' => $row['date_start'], 'campaign' => $row['campaign_name'], 'cost' => (float) $row['spend'],
                    'currency' => is_string($currency) ? $currency : 'USD',
                    'clicks' => is_numeric($row['clicks'] ?? null) ? (int) $row['clicks'] : null,
                    'impressions' => is_numeric($row['impressions'] ?? null) ? (int) $row['impressions'] : null,
                ];
            }
            $next = $response->json('paging.next');
            $url = is_string($next) && str_starts_with($next, 'https://graph.facebook.com/') ? $next : null;
        }

        return $rows;
    }

    /**
     * Return the response when it succeeded, or throw with Meta's message.
     *
     * @param  Response  $response
     * @return Response
     */
    private function check(Response $response): Response
    {
        if ($response->failed()) {
            $message = $response->json('error.message');
            throw new RuntimeException(__('Meta answered: :message', ['message' => is_string($message) ? $message : 'HTTP '.$response->status()]));
        }

        return $response;
    }
}
