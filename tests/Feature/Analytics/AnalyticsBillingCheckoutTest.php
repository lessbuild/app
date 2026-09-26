<?php

namespace Tests\Feature\Analytics;

use App\Core\Models\PlatformUser;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCatalog;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCheckout;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingException;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingReturnTarget;
use App\Modules\Analytics\Services\Billing\AnalyticsStripeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnalyticsBillingCheckoutTest extends TestCase
{
    public function test_empty_catalog_does_not_allow_any_analytics_plan_or_price(): void
    {
        config(['analytics.billing.plans' => []]);
        $catalog = app(AnalyticsBillingCatalog::class);

        $this->assertSame([], $catalog->plans());
        $this->assertNull($catalog->forKey('pro'));
        $this->assertNull($catalog->forPrice('price_fixture_analytics'));
    }

    public function test_catalog_returns_only_complete_unique_two_decimal_price_snapshots(): void
    {
        $plans = [
            'pro' => $this->plan('price_fixture_pro'),
            'jpy' => [...$this->plan('price_fixture_jpy'), 'currency' => 'JPY'],
            'incomplete' => [...$this->plan('price_fixture_incomplete'), 'snapshot' => [
                'name' => 'Analytics Pro',
                'entitlements' => ['event_collection'],
                'limits' => ['sites' => 10],
            ]],
            'empty_entitlements' => [...$this->plan('price_fixture_empty'), 'snapshot' => [
                ...$this->plan('price_fixture_empty')['snapshot'],
                'entitlements' => [],
            ]],
        ];
        config(['analytics.billing.plans' => $plans]);

        $catalog = app(AnalyticsBillingCatalog::class);
        $allowed = $catalog->forKey('pro');

        $this->assertNotNull($allowed);
        $this->assertSame('price_fixture_pro', $catalog->forPrice('price_fixture_pro')['price_id']);
        $this->assertSame('usd', $allowed['currency']);
        $this->assertSame($plans['pro']['snapshot']['limits'], $allowed['snapshot']['limits']);
        $this->assertNull($catalog->forKey('jpy'));
        $this->assertNull($catalog->forKey('incomplete'));
        $this->assertNull($catalog->forKey('empty_entitlements'));
    }

    public function test_catalog_exposes_canonical_price_terms_and_a_stable_hash(): void
    {
        config(['analytics.billing.plans' => ['pro' => $this->plan('price_fixture_pro')]]);
        $catalog = app(AnalyticsBillingCatalog::class);
        $plan = $catalog->forKey('pro');
        $this->assertNotNull($plan);

        $terms = $catalog->priceTerms($plan);

        $this->assertSame([
            'price_id' => 'price_fixture_pro',
            'amount' => 1200,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'billing_scheme' => 'per_unit',
            'usage_type' => 'licensed',
            'transform_quantity' => null,
        ], $terms);
        $this->assertSame($catalog->termsHash($plan), $catalog->storedTermsHash($terms));
        $this->assertNotSame(
            $catalog->termsHash($plan),
            $catalog->storedTermsHash([...$terms, 'amount' => 1201]),
        );
    }

    public function test_duplicate_provider_prices_are_not_allowlisted_for_either_plan(): void
    {
        $plans = [
            'pro' => $this->plan('price_fixture_duplicate'),
            'team' => [...$this->plan('price_fixture_duplicate'), 'name' => 'Analytics Team', 'snapshot' => [
                ...$this->plan('price_fixture_duplicate')['snapshot'],
                'name' => 'Analytics Team',
            ]],
        ];
        config(['analytics.billing.plans' => $plans]);

        $catalog = app(AnalyticsBillingCatalog::class);

        $this->assertSame([], $catalog->plans());
        $this->assertNull($catalog->forPrice('price_fixture_duplicate'));
    }

    public function test_checkout_is_rejected_when_purchases_or_signed_webhooks_are_disabled(): void
    {
        config([
            'analytics.billing.enabled' => true,
            'analytics.billing.webhooks_enabled' => false,
            'analytics.plan_authority' => 'core',
        ]);

        $this->expectException(AnalyticsBillingException::class);
        app(AnalyticsBillingCheckout::class)->start(
            new Workspace,
            new PlatformUser,
            'pro',
            'checkout-request-key-12345',
            'https://analytics.example/workspaces/1/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'https://analytics.example/workspaces/1/billing/canceled',
        );
    }

    public function test_return_target_requires_configured_analytics_origin_and_workspace_route(): void
    {
        config(['platform.products.analytics.url' => 'https://analytics.example']);
        $workspace = new Workspace;
        $workspace->setAttribute('id', 41);
        $targets = app(AnalyticsBillingReturnTarget::class);

        $targets->assertAllowed(
            'https://analytics.example/workspaces/41/billing/success?session_id={CHECKOUT_SESSION_ID}',
            $workspace,
            'success',
        );

        try {
            $targets->assertAllowed(
                'https://attacker.example/workspaces/41/billing/success?session_id={CHECKOUT_SESSION_ID}',
                $workspace,
                'success',
            );
            $this->fail('An attacker-controlled HTTPS return origin must be rejected.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }

        $this->expectException(AnalyticsBillingException::class);
        $targets->assertAllowed(
            'https://analytics.example/workspaces/42/billing',
            $workspace,
            'portal',
        );
    }

    public function test_client_rejects_live_key_with_attacker_controlled_api_origin(): void
    {
        config([
            'analytics.billing.stripe.secret' => 'sk_live_fixture123',
            'analytics.billing.stripe.account_id' => 'acct_fixture123',
            'analytics.billing.stripe.api_url' => 'https://api.stripe.attacker.example',
        ]);

        $client = app(AnalyticsStripeClient::class);

        $this->assertFalse($client->configured());
        $this->assertTrue($client->liveMode());
    }

    public function test_client_accepts_test_checkout_id_only_with_stripe_hosted_url(): void
    {
        $this->configureTestStripe();
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_fixture123'], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_fixture123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_fixture123',
                'mode' => 'subscription',
                'status' => 'open',
                'expires_at' => now()->addHour()->timestamp,
                'customer' => null,
            ], 200),
        ]);

        $session = app(AnalyticsStripeClient::class)->createCheckoutSession(
            ['mode' => 'subscription'],
            'analytics-checkout-fixture-key',
        );

        $this->assertSame('cs_test_fixture123', $session['id']);
        $this->assertSame('open', $session['status']);
    }

    public function test_client_caches_verified_account_only_for_the_same_credentials_and_account(): void
    {
        $this->configureTestStripe();
        $accountCalls = 0;
        Http::fake(function ($request) use (&$accountCalls) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/v1/account') {
                $accountCalls++;

                return Http::response(['id' => 'acct_fixture123'], 200);
            }

            if (parse_url($request->url(), PHP_URL_PATH) === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $client = app(AnalyticsStripeClient::class);
        $client->retrievePrice('price_fixture_pro');
        $client->retrievePrice('price_fixture_pro');
        $this->assertSame(1, $accountCalls);

        config(['analytics.billing.stripe.secret' => 'sk_test_rotatedfixture456']);
        $client->retrievePrice('price_fixture_pro');
        $this->assertSame(2, $accountCalls, 'Changed Stripe credentials require a fresh account verification.');
    }

    public function test_client_rejects_a_provider_response_that_redirects_to_an_attacker_host(): void
    {
        $this->configureTestStripe();
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_fixture123'], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_fixture123',
                'url' => 'https://checkout.stripe.attacker.example/c/pay/session',
                'status' => 'open',
                'expires_at' => now()->addHour()->timestamp,
            ], 200),
        ]);

        $this->expectException(AnalyticsBillingException::class);
        app(AnalyticsStripeClient::class)->createCheckoutSession(
            ['mode' => 'subscription'],
            'analytics-checkout-fixture-key',
        );
    }

    public function test_checkout_price_verification_rejects_tiered_or_metered_prices(): void
    {
        $this->configureTestStripe();
        config(['analytics.billing.plans' => ['pro' => $this->plan('price_fixture_pro')]]);
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_fixture123'], 200),
            'https://api.stripe.com/v1/prices/price_fixture_pro' => Http::response($this->providerPrice([
                'billing_scheme' => 'tiered',
                'transform_quantity' => ['divide_by' => 100],
                'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'metered'],
            ]), 200),
        ]);

        $this->expectException(AnalyticsBillingException::class);
        app(AnalyticsBillingCheckout::class)->assertPriceMatchesCatalog(app(AnalyticsBillingCatalog::class)->forKey('pro'));
    }

    public function test_checkout_price_verification_rejects_test_and_live_mode_mismatch(): void
    {
        $this->configureTestStripe();
        config(['analytics.billing.plans' => ['pro' => $this->plan('price_fixture_pro')]]);
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_fixture123'], 200),
            'https://api.stripe.com/v1/prices/price_fixture_pro' => Http::response($this->providerPrice([
                'livemode' => true,
            ]), 200),
        ]);

        $this->expectException(AnalyticsBillingException::class);
        app(AnalyticsBillingCheckout::class)->assertPriceMatchesCatalog(app(AnalyticsBillingCatalog::class)->forKey('pro'));
    }

    private function configureTestStripe(): void
    {
        config([
            'analytics.billing.stripe.secret' => 'sk_test_fixture123',
            'analytics.billing.stripe.account_id' => 'acct_fixture123',
            'analytics.billing.stripe.api_url' => 'https://api.stripe.com',
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function providerPrice(array $overrides = []): array
    {
        return array_replace([
            'id' => 'price_fixture_pro',
            'active' => true,
            'livemode' => false,
            'type' => 'recurring',
            'billing_scheme' => 'per_unit',
            'transform_quantity' => null,
            'unit_amount' => 1200,
            'currency' => 'usd',
            'recurring' => [
                'interval' => 'month',
                'interval_count' => 1,
                'usage_type' => 'licensed',
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function plan(string $priceId): array
    {
        return [
            'name' => 'Analytics Pro',
            'description' => 'Inert fixture used only to exercise catalog validation.',
            'price_id' => $priceId,
            'amount' => 1200,
            'currency' => 'USD',
            'interval' => 'month',
            'interval_count' => 1,
            'snapshot' => [
                'name' => 'Analytics Pro',
                'description' => 'Inert fixture used only to exercise catalog validation.',
                'entitlements' => ['event_collection', 'site_management'],
                'limits' => [
                    'sites' => 10,
                    'members' => 5,
                    'events_per_month' => 100000,
                    'retention_days' => 90,
                    'aggregate_retention_months' => 13,
                    'export_retention_hours' => 24,
                ],
            ],
        ];
    }
}
