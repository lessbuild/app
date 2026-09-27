<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Domain\Billing\Data\LineItem;
use App\Domain\Billing\Exceptions\InvalidWebhook;
use App\Services\Billing\StripePaymentProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

final class StripePaymentProviderTest extends TestCase
{
    /** @var list<array{method: string, url: string, params: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
        $subscription = [
            'id' => 'sub_1', 'object' => 'subscription', 'status' => 'active',
            // An item created by Checkout: no item metadata, recognised through the subscription's price map.
            'metadata' => ['buildpusher_references' => '{"price_deploy_pro":"deploy:tier:pro"}'],
            'items' => ['object' => 'list', 'data' => [
                ['id' => 'si_deploy', 'object' => 'subscription_item', 'price' => ['id' => 'price_deploy_pro', 'object' => 'price'], 'metadata' => [], 'current_period_end' => 1_800_000_000],
                ['id' => 'si_seats', 'object' => 'subscription_item', 'price' => ['id' => 'price_seat', 'object' => 'price'], 'metadata' => ['reference' => 'deploy:addon:seat'], 'current_period_end' => 1_800_000_000],
            ]],
        ];
        $test = $this;
        ApiRequestor::setHttpClient(new class($subscription, $test) implements ClientInterface
        {
            /** @param array<string, mixed> $subscription */
            public function __construct(private array $subscription, private StripePaymentProviderTest $test) {}

            /**
             * @param  list<string>  $headers
             * @param  array<string, mixed>  $params
             * @return array{0: string, 1: int, 2: array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->test->record($method, $absUrl, $params);

                return [(string) json_encode($this->subscription), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /** @param array<string, mixed> $params */
    public function record(string $method, string $url, array $params): void
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'params' => $params];
    }

    public function test_sync_updates_a_tier_in_place_adds_new_items_and_removes_unwanted_ones(): void
    {
        $provider = new StripePaymentProvider(new StripeClient('sk_test_x'), null);

        $state = $provider->syncSubscription('sub_1', [
            new LineItem('deploy:tier:team', 'price_deploy_team'),
            new LineItem('monitoring:tier:pro', 'price_mon_pro'),
        ]);

        $update = $this->requests[1];
        $this->assertSame('post', $update['method']);
        $this->assertStringEndsWith('/v1/subscriptions/sub_1', $update['url']);
        $this->assertSame('create_prorations', $update['params']['proration_behavior']);
        $this->assertSame([
            ['id' => 'si_deploy', 'price' => 'price_deploy_team', 'quantity' => 1, 'metadata' => ['reference' => 'deploy:tier:team']],
            ['price' => 'price_mon_pro', 'quantity' => 1, 'metadata' => ['reference' => 'monitoring:tier:pro']],
            ['id' => 'si_seats', 'deleted' => 'true'], // stripe-php sends booleans as strings
        ], $update['params']['items']);
        $this->assertSame('active', $state->status);
        $this->assertSame(1_800_000_000, $state->currentPeriodEnd?->getTimestamp());
        $this->assertSame(['deploy:tier:pro' => 'si_deploy', 'deploy:addon:seat' => 'si_seats'], $state->itemIds);
    }

    public function test_webhooks_are_verified_with_the_signing_secret(): void
    {
        $provider = new StripePaymentProvider(new StripeClient('sk_test_x'), 'whsec_test');
        $payload = (string) json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'past_due']]]);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $event = $provider->verifyWebhook($payload, $signature);
        $this->assertSame(['evt_1', 'customer.subscription.updated', 'past_due'], [$event->id, $event->type, $event->object['status']]);

        $this->expectException(InvalidWebhook::class);
        $provider->verifyWebhook($payload, 't='.$timestamp.',v1=forged');
    }
}
