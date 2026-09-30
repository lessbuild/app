<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use App\Models\ProviderBill;
use App\Support\AwsSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Imports what cloud providers actually charged: this month's usage so far and past invoices, from DigitalOcean,
 * Vultr and Linode, and Lightsail's costs from AWS Cost Explorer. Hetzner Cloud has no billing API.
 */
final class CloudBills
{
    /**
     * The provider types whose bills can be read.
     *
     * @var list<ProviderType>
     */
    public const SUPPORTED = [ProviderType::DigitalOcean, ProviderType::Vultr, ProviderType::Linode, ProviderType::Lightsail];

    /**
     * Read the provider's bills and store them by month; a failure is noted on the provider (a token without billing
     * access, say) and nothing else changes.
     *
     * @param  Provider  $provider
     * @return int months stored
     */
    public function refresh(Provider $provider): int
    {
        if (! in_array($provider->type, self::SUPPORTED, true)) {
            return 0;
        }
        try {
            $bills = match ($provider->type) {
                ProviderType::DigitalOcean => $this->digitalOcean($provider->token),
                ProviderType::Vultr => $this->vultr($provider->token),
                ProviderType::Linode => $this->linode($provider->token),
                default => $this->lightsail($provider->token),
            };
        } catch (Throwable $exception) {
            $provider->forceFill(['billing_error' => mb_substr($exception->getMessage(), 0, 300), 'billing_checked_at' => now()])->save();

            return 0;
        }
        foreach ($bills as $period => [$amount, $final]) {
            $bill = ProviderBill::query()->where('provider_id', $provider->id)->where('period', $period)->first() ?? new ProviderBill;
            $bill->forceFill(['provider_id' => $provider->id, 'period' => $period, 'amount' => round($amount, 2), 'currency' => 'USD', 'final' => $final])->save();
        }
        $provider->forceFill(['billing_error' => null, 'billing_checked_at' => now()])->save();

        return count($bills);
    }

    /**
     * Read DigitalOcean's month-to-date usage and invoices.
     *
     * @param  string  $token
     * @return array<string, array{float, bool}>
     */
    private function digitalOcean(string $token): array
    {
        $client = Http::baseUrl('https://api.digitalocean.com/v2')->withToken($token)->acceptJson()->timeout(15);
        $bills = [];
        foreach ((array) $this->ok($client->get('/customers/my/invoices', ['per_page' => 12]), 'DigitalOcean')->json('invoices', []) as $invoice) {
            if (is_array($invoice) && is_string($invoice['invoice_period'] ?? null) && is_numeric($invoice['amount'] ?? null)) {
                $bills[$invoice['invoice_period']] = [(float) $invoice['amount'], true];
            }
        }
        $usage = $this->ok($client->get('/customers/my/balance'), 'DigitalOcean')->json('month_to_date_usage');
        if (is_numeric($usage)) {
            $bills[now()->format('Y-m')] = [(float) $usage, false];
        }

        return $bills;
    }

    /**
     * Read Vultr's pending charges and invoices.
     *
     * @param  string  $token
     * @return array<string, array{float, bool}>
     */
    private function vultr(string $token): array
    {
        $client = Http::baseUrl('https://api.vultr.com/v2')->withToken($token)->acceptJson()->timeout(15);
        $bills = [];
        foreach ((array) $this->ok($client->get('/billing/invoices', ['per_page' => 24]), 'Vultr')->json('billing_invoices', []) as $invoice) {
            if (is_array($invoice) && is_string($invoice['date'] ?? null) && is_numeric($invoice['amount'] ?? null)) {
                // An invoice dated at the start of a month bills the month before.
                $bills[CarbonImmutable::parse($invoice['date'])->subDay()->format('Y-m')] = [(float) $invoice['amount'], true];
            }
        }
        $pending = $this->ok($client->get('/account'), 'Vultr')->json('account.pending_charges');
        if (is_numeric($pending)) {
            $bills[now()->format('Y-m')] = [(float) $pending, false];
        }

        return $bills;
    }

    /**
     * Read Linode's uninvoiced balance and invoices.
     *
     * @param  string  $token
     * @return array<string, array{float, bool}>
     */
    private function linode(string $token): array
    {
        $client = Http::baseUrl('https://api.linode.com/v4')->withToken($token)->acceptJson()->timeout(15);
        $bills = [];
        foreach ((array) $this->ok($client->get('/account/invoices', ['page_size' => 25]), 'Linode')->json('data', []) as $invoice) {
            if (is_array($invoice) && is_string($invoice['date'] ?? null) && is_numeric($invoice['total'] ?? null)) {
                $bills[CarbonImmutable::parse($invoice['date'])->subDay()->format('Y-m')] = [(float) $invoice['total'], true];
            }
        }
        $uninvoiced = $this->ok($client->get('/account'), 'Linode')->json('balance_uninvoiced');
        if (is_numeric($uninvoiced)) {
            $bills[now()->format('Y-m')] = [(float) $uninvoiced, false];
        }

        return $bills;
    }

    /**
     * Read Lightsail's cost by month for the last six months from AWS Cost Explorer (the key needs ce:GetCostAndUsage).
     *
     * @param  string  $credential  "ACCESS_KEY_ID:SECRET"
     * @return array<string, array{float, bool}>
     */
    private function lightsail(string $credential): array
    {
        [$key, $secret] = array_pad(explode(':', $credential, 2), 2, '');
        $url = 'https://ce.us-east-1.amazonaws.com/';
        $body = (string) json_encode([
            'TimePeriod' => ['Start' => now()->startOfMonth()->subMonths(5)->toDateString(), 'End' => now()->addDay()->toDateString()],
            'Granularity' => 'MONTHLY', 'Metrics' => ['UnblendedCost'],
            'Filter' => ['Dimensions' => ['Key' => 'SERVICE', 'Values' => ['Amazon Lightsail']]],
        ]);
        $headers = AwsSignature::headers($key, $secret, 'us-east-1', 'ce', 'POST', $url, ['Content-Type' => 'application/x-amz-json-1.1', 'X-Amz-Target' => 'AWSInsightsIndexService.GetCostAndUsage'], $body);
        unset($headers['Host']);
        $response = $this->ok(Http::withHeaders($headers)->withBody($body, 'application/x-amz-json-1.1')->timeout(15)->post($url), 'AWS Cost Explorer');
        $bills = [];
        foreach ((array) $response->json('ResultsByTime', []) as $month) {
            $start = is_array($month) ? ($month['TimePeriod']['Start'] ?? null) : null;
            $amount = is_array($month) ? ($month['Total']['UnblendedCost']['Amount'] ?? null) : null;
            if (is_string($start) && is_numeric($amount)) {
                $period = substr($start, 0, 7);
                $bills[$period] = [(float) $amount, $period !== now()->format('Y-m')];
            }
        }

        return $bills;
    }

    /**
     * Return the response when it succeeded, or throw with the provider's status.
     *
     * @param  Response  $response
     * @param  string  $name
     * @return Response
     */
    private function ok(Response $response, string $name): Response
    {
        if ($response->failed()) {
            throw new RuntimeException($response->status() === 403 || $response->status() === 401
                ? "{$name} refused the billing request: give the credential billing read access."
                : "{$name} answered HTTP {$response->status()} for billing.");
        }

        return $response;
    }
}
