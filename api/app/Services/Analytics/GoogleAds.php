<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\Analytics\AdPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Google Ads campaign spend through the Google Ads API, with the platform's Google OAuth client (the adwords scope)
 * and its Google Ads developer token (services.google_ads.developer_token).
 */
final class GoogleAds implements AdPlatform
{
    /** The Google Ads API version. */
    private const string API = 'https://googleads.googleapis.com/v21';

    /** The OAuth scope for reading Google Ads. */
    private const string SCOPE = 'https://www.googleapis.com/auth/adwords';

    /**
     * Create a new GoogleAds instance.
     *
     * @param  GoogleOAuth  $google  The platform's Google OAuth client.
     */
    public function __construct(private readonly GoogleOAuth $google = new GoogleOAuth) {}

    /**
     * Determine whether Google OAuth and a developer token are set up.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->google->configured() && filled(config('services.google_ads.developer_token'));
    }

    /**
     * Get the Google sign-in address asking for Google Ads access.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return $this->google->authorizationUrl(self::SCOPE, route('analytics.ads.callback', 'google'), $state);
    }

    /**
     * Swap Google's code for a refresh token.
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string
    {
        return $this->google->exchange($code, route('analytics.ads.callback', 'google'));
    }

    /**
     * List the Google Ads customers the person can read, with their names and currencies.
     *
     * @param  string  $credential
     * @return list<array{id: string, name: string, currency: string|null}>
     */
    public function accounts(string $credential): array
    {
        $response = $this->api($credential)->get(self::API.'/customers:listAccessibleCustomers');
        $this->google->check($response);
        $accounts = [];
        foreach ((array) $response->json('resourceNames', []) as $resource) {
            $id = is_string($resource) ? substr($resource, strlen('customers/')) : '';
            if (! ctype_digit($id)) {
                continue;
            }
            $details = $this->api($credential)->post(self::API."/customers/{$id}/googleAds:search", ['query' => 'SELECT customer.descriptive_name, customer.currency_code, customer.manager FROM customer LIMIT 1']);
            $customer = $details->successful() ? $details->json('results.0.customer') : null;
            if (is_array($customer) && ($customer['manager'] ?? false) === true) {
                continue;
            }
            $accounts[] = [
                'id' => $id,
                'name' => is_array($customer) && is_string($customer['descriptiveName'] ?? null) && $customer['descriptiveName'] !== '' ? $customer['descriptiveName'] : $this->format($id),
                'currency' => is_array($customer) && is_string($customer['currencyCode'] ?? null) ? $customer['currencyCode'] : null,
            ];
        }

        return $accounts;
    }

    /**
     * Read a customer's cost, clicks and impressions per campaign and day.
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
            throw new RuntimeException(__('That isn’t a Google Ads customer ID.'));
        }
        $query = sprintf(
            "SELECT segments.date, campaign.name, metrics.cost_micros, metrics.clicks, metrics.impressions, customer.currency_code FROM campaign WHERE segments.date BETWEEN '%s' AND '%s' AND metrics.cost_micros > 0",
            $from->toDateString(), $until->toDateString(),
        );
        $response = $this->api($credential)->post(self::API."/customers/{$accountId}/googleAds:searchStream", ['query' => $query]);
        $this->google->check($response);
        $rows = [];
        foreach ((array) $response->json() as $batch) {
            foreach ((array) (is_array($batch) ? ($batch['results'] ?? []) : []) as $result) {
                if (! is_array($result) || ! is_string($result['segments']['date'] ?? null) || ! is_string($result['campaign']['name'] ?? null)) {
                    continue;
                }
                $rows[] = [
                    'date' => $result['segments']['date'], 'campaign' => $result['campaign']['name'],
                    'cost' => (float) ($result['metrics']['costMicros'] ?? 0) / 1_000_000,
                    'currency' => is_string($result['customer']['currencyCode'] ?? null) ? $result['customer']['currencyCode'] : 'USD',
                    'clicks' => isset($result['metrics']['clicks']) ? (int) $result['metrics']['clicks'] : null,
                    'impressions' => isset($result['metrics']['impressions']) ? (int) $result['metrics']['impressions'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Start a Google Ads API request with the developer token.
     *
     * @param  string  $credential
     * @return PendingRequest
     */
    private function api(string $credential): PendingRequest
    {
        return $this->google->client($credential, __('Google no longer accepts this Google Ads connection. Connect it again.'))
            ->withHeaders(['developer-token' => (string) config('services.google_ads.developer_token')]);
    }

    /**
     * Format a customer ID the way Google Ads shows it (123-456-7890).
     *
     * @param  string  $id
     * @return string
     */
    private function format(string $id): string
    {
        return strlen($id) === 10 ? substr($id, 0, 3).'-'.substr($id, 3, 3).'-'.substr($id, 6) : $id;
    }
}
