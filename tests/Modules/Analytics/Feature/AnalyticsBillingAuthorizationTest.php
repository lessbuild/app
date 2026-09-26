<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingAccess;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCatalog;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCheckout;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingException;
use App\Modules\Analytics\Services\Billing\ReconcileAnalyticsCheckoutAttempt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsBillingAuthorizationTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_core_billing_manager_can_manage_billing_with_native_viewer_membership(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');

        $context = app(AnalyticsBillingAccess::class)->authorize($fixture['analytics_workspace'], $actor['platform_user']);

        $this->assertSame((string) $fixture['core_workspace']->getKey(), (string) $context->coreWorkspace->getKey());
        $this->assertSame('billing', $context->membership->role);
        $this->assertSame((string) $actor['native_user_id'], $context->nativeUserId);
    }

    public function test_core_member_cannot_manage_billing_even_with_native_membership_and_product_grant(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'member', nativeRole: 'admin', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');

        $this->expectException(AuthorizationException::class);
        app(AnalyticsBillingAccess::class)->authorize($fixture['analytics_workspace'], $actor['platform_user']);
    }

    public function test_core_billing_manager_without_current_analytics_product_grant_is_denied(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: false);
        $this->actingAs($actor['platform_user'], 'platform');

        $this->expectException(AuthorizationException::class);
        app(AnalyticsBillingAccess::class)->authorize($fixture['analytics_workspace'], $actor['platform_user']);
    }

    public function test_purchase_availability_checks_the_core_plan_without_provider_calls(): void
    {
        $fixture = $this->workspaceFixture();
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        Http::fake();

        $available = app(AnalyticsBillingCheckout::class)->purchaseAvailable(
            $fixture['analytics_workspace'],
            $fixture['core_workspace'],
            'acct_fixture123',
        );

        $this->assertTrue($available);
        Http::assertNothingSent();
    }

    public function test_checkout_retry_reuses_attempt_for_initiator_and_rejects_another_manager_key_reuse(): void
    {
        $fixture = $this->workspaceFixture();
        $firstActor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $secondActor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();

        $attemptId = null;
        $checkoutPosts = 0;
        $sessionGets = 0;
        $coreStatements = [];
        DB::listen(static function (QueryExecuted $query) use (&$coreStatements): void {
            if ($query->connectionName === 'core') {
                $coreStatements[] = strtolower($query->sql);
            }
        });
        $coreWorkspaceId = (string) $fixture['core_workspace']->getKey();
        $analyticsWorkspaceId = (string) $fixture['analytics_workspace']->getKey();
        Http::fake(function (ClientRequest $request, array $options) use (
            &$attemptId,
            &$checkoutPosts,
            &$sessionGets,
            $coreWorkspaceId,
            $analyticsWorkspaceId,
        ) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }

            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }

            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;

                return Http::response($this->providerCheckoutResponse($request, 'cs_test_analyticsfixture123'), 200);
            }

            if ($path === '/v1/checkout/sessions/cs_test_analyticsfixture123') {
                $sessionGets++;

                return Http::response([
                    'id' => 'cs_test_analyticsfixture123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_analyticsfixture123',
                    'mode' => 'subscription',
                    'client_reference_id' => $attemptId,
                    'status' => 'open',
                    'expires_at' => now()->addHour()->timestamp,
                    'customer' => null,
                    'metadata' => [
                        'product' => 'analytics',
                        'core_workspace_id' => $coreWorkspaceId,
                        'analytics_workspace_id' => $analyticsWorkspaceId,
                        'provider_account_key' => 'acct_fixture123',
                        'checkout_attempt_id' => $attemptId,
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $idempotencyKey = 'analytics-checkout-key-2026';
        $this->actingAs($firstActor['platform_user'], 'platform');
        $first = $this->startCheckout($fixture['analytics_workspace'], $firstActor['platform_user'], $idempotencyKey);
        $attempt = AnalyticsCheckoutAttempt::query()->sole();
        $attemptId = (string) $attempt->getKey();

        $retry = $this->startCheckout($fixture['analytics_workspace'], $firstActor['platform_user'], $idempotencyKey);

        $this->assertSame('open', $first['status']);
        $this->assertFalse($first['reused']);
        $this->assertTrue($retry['reused']);
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame((string) $firstActor['platform_user']->getKey(), $attempt->initiated_by_user_id);
        $this->assertSame(1, $checkoutPosts);
        $this->assertSame(1, $sessionGets);
        $attemptRead = null;
        $workspaceFence = null;
        foreach ($coreStatements as $index => $statement) {
            if ($attemptRead === null && str_contains($statement, 'analytics_checkout_attempts')) {
                $attemptRead = $index;
            }
            if ($workspaceFence === null && str_contains($statement, 'update "workspaces" set "updated_at"')) {
                $workspaceFence = $index;
            }
        }
        $this->assertNotNull($attemptRead, 'The checkout path must inspect the durable attempt table.');
        $this->assertNotNull($workspaceFence, 'The checkout path must acquire its Core workspace write fence.');
        $this->assertLessThan($attemptRead, $workspaceFence, 'The workspace write must precede attempt reads on SQLite.');

        $this->actingAs($secondActor['platform_user'], 'platform');
        $this->expectException(AnalyticsBillingException::class);
        $this->startCheckout($fixture['analytics_workspace'], $secondActor['platform_user'], $idempotencyKey);
    }

    public function test_failed_key_cannot_create_a_second_checkout_while_another_attempt_is_open(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();

        $failed = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-failed-key-2026', 'failed');
        $open = $this->makeAttempt(
            $fixture,
            $actor['platform_user'],
            'analytics-open-key-2026',
            'open',
            'cs_test_existingfixture123',
        );
        $openId = (string) $open->getKey();
        $checkoutPosts = 0;
        $sessionGets = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts, &$sessionGets, $fixture, $openId) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;

                return Http::response(['error' => 'A second session must not be created.'], 409);
            }
            if ($path === '/v1/checkout/sessions/cs_test_existingfixture123') {
                $sessionGets++;

                return Http::response([
                    'id' => 'cs_test_existingfixture123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_existingfixture123',
                    'mode' => 'subscription',
                    'client_reference_id' => $openId,
                    'status' => 'open',
                    'expires_at' => now()->addHour()->timestamp,
                    'customer' => null,
                    'metadata' => [
                        'product' => 'analytics',
                        'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
                        'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
                        'provider_account_key' => 'acct_fixture123',
                        'checkout_attempt_id' => $openId,
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-failed-key-2026',
            );
            $this->fail('An unresolved failed attempt and a later open attempt must block another checkout.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, $checkoutPosts);
        $this->assertSame(0, $sessionGets);
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_unresolved_failed_attempt_resumes_for_same_actor_and_terms_under_new_page_key(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-original-failed-key-2026', 'failed');
        $providerKeys = [];
        Http::fake(function (ClientRequest $request) use (&$providerKeys) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $providerKeys[] = $request->header('Idempotency-Key')[0] ?? null;

                return Http::response($this->providerCheckoutResponse($request, 'cs_test_recoveredfixture123'), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $result = $this->startCheckout(
            $fixture['analytics_workspace'],
            $actor['platform_user'],
            'analytics-new-failed-retry-key-2026',
        );

        $this->assertSame('cs_test_recoveredfixture123', $result['id']);
        $this->assertSame([
            'analytics-checkout-'.hash('sha256', 'checkout|'.$attempt->getKey()),
        ], $providerKeys);
        $this->assertSame(1, AnalyticsCheckoutAttempt::query()->count(), 'Recovery must resume the original attempt, not create another.');
    }

    public function test_aged_uncertain_attempt_stays_exclusive_and_cannot_replay_after_provider_idempotency_window(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-aged-failed-key-2026', 'failed');
        AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())
            ->update(['created_at' => now('UTC')->subHours(13)]);
        $checkoutPosts = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], 'analytics-new-aged-key-2026');
            $this->fail('An aged uncertain checkout must require provider reconciliation.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('provider reconciliation', $exception->getMessage());
        }
        $this->assertSame(0, $checkoutPosts);
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame(1, AnalyticsCheckoutAttempt::query()->count());
    }

    public function test_recovered_completed_session_blocks_new_checkout_until_core_subscription_projection(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $attempt = $this->makeAttempt(
            $fixture, $actor['platform_user'], 'analytics-recovered-complete-key-2026',
            'completed', 'cs_test_recoveredcomplete123', 'sub_recoveredcomplete123',
        );
        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }

            return Http::response(['error' => 'No new Checkout Session may be created.'], 409);
        });

        try {
            $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], 'analytics-second-after-complete-2026');
            $this->fail('A recovered paid Session must keep the slot reserved until signed Core projection.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('awaiting signed subscription reconciliation', $exception->getMessage());
        }
        $this->assertSame('completed', $attempt->fresh()->status);
        Http::assertNotSent(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v1/checkout/sessions'
            && $request->method() === 'POST');
    }

    public function test_historical_subscription_row_without_current_slot_pointer_does_not_release_completed_attempt(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $attempt = $this->makeAttempt(
            $fixture, $actor['platform_user'], 'analytics-historical-complete-key-2026',
            'completed', 'cs_test_historical123', 'sub_historical123',
        );
        $customer = BillingCustomer::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(), 'provider' => 'stripe',
            'provider_account_key' => 'acct_fixture123', 'provider_customer_id' => 'cus_test_historical123',
            'status' => 'active',
        ]);
        ProductSubscription::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(), 'billing_customer_id' => $customer->getKey(),
            'product' => 'analytics', 'provider' => 'stripe', 'provider_account_key' => 'acct_fixture123',
            'provider_subscription_id' => 'sub_historical123', 'provider_price_id' => 'price_fixture_pro',
            'plan_key' => 'pro', 'status' => 'canceled', 'quantity' => 1,
            'metadata' => ['billing_state' => ['checkout_attempt_id' => (string) $attempt->getKey()]],
        ]);
        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }

            return Http::response(['error' => 'No new Checkout Session may be created.'], 409);
        });

        try {
            $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], 'analytics-after-historical-2026');
            $this->fail('A historical row cannot settle a paid checkout while the Core slot is still legacy.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('awaiting signed subscription reconciliation', $exception->getMessage());
        }
        Http::assertNotSent(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v1/checkout/sessions'
            && $request->method() === 'POST');
    }

    public function test_stale_local_cancellation_does_not_allow_renewal_while_provider_subscription_is_live(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $renewal = $this->initializeCanceledAnalyticsSlot($fixture, $actor['platform_user']);
        $this->configureBilling();
        $checkoutPosts = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts, $fixture, $renewal) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/subscriptions/'.$renewal['subscription']->provider_subscription_id) {
                return Http::response($this->providerSubscriptionFor($fixture, $renewal, [
                    'status' => 'active',
                    'cancel_at_period_end' => true,
                ]), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], 'analytics-stale-cancel-key-2026');
            $this->fail('A locally canceled subscription must not renew while Stripe reports it as active.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('terminal Analytics subscription', $exception->getMessage());
        }

        $this->assertSame(0, $checkoutPosts);
    }

    public function test_canceled_slot_purchase_availability_fails_closed_when_stripe_is_unreachable(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $renewal = $this->initializeCanceledAnalyticsSlot($fixture, $actor['platform_user']);
        $this->configureBilling();
        Http::fake(function (ClientRequest $request) use ($renewal) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/subscriptions/'.$renewal['subscription']->provider_subscription_id) {
                throw new ConnectionException('Stripe is temporarily unreachable.');
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $available = app(AnalyticsBillingCheckout::class)->purchaseAvailable(
            $fixture['analytics_workspace'],
            $fixture['core_workspace'],
            'acct_fixture123',
        );

        $this->assertFalse($available);
        $this->actingAs($actor['platform_user'], 'platform')
            ->get(route('analytics.workspaces.billing', $fixture['analytics_workspace']))
            ->assertOk();
    }

    public function test_renewal_is_blocked_when_customer_has_another_live_analytics_subscription(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $renewal = $this->initializeCanceledAnalyticsSlot($fixture, $actor['platform_user']);
        $this->configureBilling();
        $other = $this->providerSubscriptionFor($fixture, $renewal, [
            'id' => 'sub_test_otheranalyticsfixture123',
            'status' => 'active',
            'metadata' => [
                'product' => 'analytics',
                'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
                'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
                'provider_account_key' => 'acct_fixture123',
                'checkout_attempt_id' => '01K6F4VYGW0H8K9JDKBN6T5N3P',
            ],
        ]);
        $checkoutPosts = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts, $renewal, $other, $fixture) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/subscriptions/'.$renewal['subscription']->provider_subscription_id) {
                return Http::response($this->providerSubscriptionFor($fixture, $renewal), 200);
            }
            if ($path === '/v1/subscriptions') {
                return Http::response(['data' => [
                    $this->providerSubscriptionFor($fixture, $renewal),
                    $other,
                ], 'has_more' => false], 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], 'analytics-multiple-live-key-2026');
            $this->fail('A second live Analytics subscription for the workspace must prevent renewal checkout.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('another live Analytics subscription', $exception->getMessage());
        }

        $this->assertSame(0, $checkoutPosts);
    }

    public function test_verified_canceled_subscription_renews_on_its_existing_customer_and_exact_workspace(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $renewal = $this->initializeCanceledAnalyticsSlot($fixture, $actor['platform_user']);
        $historicalAttempt = $this->makeAttempt(
            $fixture, $actor['platform_user'], 'analytics-earlier-paid-key-2026',
            'completed', 'cs_test_earlierpaid123', 'sub_test_earlierpaid123',
        );
        AnalyticsCheckoutAttempt::query()->whereKey($historicalAttempt->getKey())
            ->update(['created_at' => now('UTC')->subDay()]);
        $historicalSubscription = ProductSubscription::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(),
            'billing_customer_id' => $renewal['customer']->getKey(),
            'product' => 'analytics', 'provider' => 'stripe', 'provider_account_key' => 'acct_fixture123',
            'provider_subscription_id' => 'sub_test_earlierpaid123', 'provider_price_id' => 'price_fixture_pro',
            'plan_key' => 'pro', 'status' => 'canceled', 'quantity' => 1,
            'metadata' => ['billing_state' => ['checkout_attempt_id' => (string) $historicalAttempt->getKey()]],
        ]);
        $this->configureBilling();
        $posted = null;
        Http::fake(function (ClientRequest $request) use (&$posted, $fixture, $renewal, $historicalAttempt, $historicalSubscription) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/subscriptions/'.$renewal['subscription']->provider_subscription_id) {
                return Http::response($this->providerSubscriptionFor($fixture, $renewal), 200);
            }
            if ($path === '/v1/subscriptions') {
                return Http::response(['data' => [
                    $this->providerSubscriptionFor($fixture, $renewal),
                    $this->providerSubscriptionFor($fixture, [
                        'attempt' => $historicalAttempt, 'customer' => $renewal['customer'], 'subscription' => $historicalSubscription,
                    ]),
                ], 'has_more' => false], 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $posted = $request->data();

                return Http::response($this->providerCheckoutResponse(
                    $request,
                    'cs_test_analyticsrenewalfixture123',
                    customerId: $renewal['customer']->provider_customer_id,
                ), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $result = $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], 'analytics-renewal-key-2026');

        $this->assertSame('cs_test_analyticsrenewalfixture123', $result['id']);
        $this->assertSame($renewal['customer']->provider_customer_id, $posted['customer']);
        $this->assertSame('analytics', $posted['metadata']['product']);
        $this->assertSame((string) $fixture['core_workspace']->getKey(), $posted['metadata']['core_workspace_id']);
        $this->assertSame((string) $fixture['analytics_workspace']->getKey(), $posted['metadata']['analytics_workspace_id']);
        $attempt = AnalyticsCheckoutAttempt::query()->whereNull('provider_subscription_id')->orderByDesc('created_at')->firstOrFail();
        $this->assertSame($renewal['customer']->provider_customer_id, $attempt->provider_customer_id);
        $this->assertSame((string) $attempt->getKey(), $posted['metadata']['checkout_attempt_id']);
    }

    public function test_aged_uncertain_attempt_operator_reconciliation_attaches_a_matching_provider_session(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-operator-session-key-2026', 'failed');
        AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())->update(['created_at' => now('UTC')->subHours(13)]);
        $attempt = $attempt->fresh();
        $this->configureBilling();
        $metadata = [
            'product' => 'analytics',
            'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
            'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
            'provider_account_key' => 'acct_fixture123',
            'checkout_attempt_id' => (string) $attempt->getKey(),
        ];
        Http::fake(function (ClientRequest $request) use ($attempt, $metadata) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/checkout/sessions') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $session = [
                    'id' => 'cs_test_operatorfoundfixture123',
                    'object' => 'checkout.session',
                    'mode' => 'subscription',
                    'client_reference_id' => (string) $attempt->getKey(),
                    'status' => 'open',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_operatorfoundfixture123',
                    'expires_at' => now()->addHour()->timestamp,
                    'customer' => null,
                    'subscription' => null,
                    'metadata' => $metadata,
                ];

                return Http::response([
                    'data' => ($query['status'] ?? null) === 'open' ? [$session] : [],
                    'has_more' => false,
                ], 200);
            }
            if ($path === '/v1/checkout/sessions/cs_test_operatorfoundfixture123') {
                return Http::response([
                    'id' => 'cs_test_operatorfoundfixture123',
                    'object' => 'checkout.session',
                    'mode' => 'subscription',
                    'client_reference_id' => (string) $attempt->getKey(),
                    'status' => 'open',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_operatorfoundfixture123',
                    'expires_at' => now()->addHour()->timestamp,
                    'customer' => null,
                    'subscription' => null,
                    'metadata' => $metadata,
                ], 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $result = app(ReconcileAnalyticsCheckoutAttempt::class)->handle((string) $attempt->getKey(), apply: true);

        $this->assertSame('provider_session_found', $result['result']);
        $this->assertTrue($result['changed']);
        $this->assertSame('cs_test_operatorfoundfixture123', $attempt->fresh()->provider_checkout_session_id);
        $this->assertSame('open', $attempt->fresh()->status);
    }

    public function test_aged_uncertain_attempt_stays_exclusive_after_negative_provider_inventory(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-operator-absent-key-2026', 'failed');
        AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())->update(['created_at' => now('UTC')->subHours(13)]);
        $attempt = $attempt->fresh();
        $this->configureBilling();
        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/checkout/sessions') {
                return Http::response(['data' => [], 'has_more' => false], 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $inspection = app(ReconcileAnalyticsCheckoutAttempt::class)->handle((string) $attempt->getKey());
        $this->assertSame('provider_session_absent', $inspection['result']);
        $this->assertSame('failed', $attempt->fresh()->status);

        $applied = app(ReconcileAnalyticsCheckoutAttempt::class)->handle((string) $attempt->getKey(), apply: true);
        $this->assertSame('provider_session_absent', $applied['result']);
        $this->assertFalse($applied['changed']);
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->provider_checkout_session_id);
    }

    public function test_operator_reconciliation_rejects_expired_session_with_missing_subscription_field(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-malformed-session-key-2026', 'failed');
        AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())->update(['created_at' => now('UTC')->subHours(13)]);
        $this->configureBilling();
        $metadata = [
            'product' => 'analytics',
            'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
            'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
            'provider_account_key' => 'acct_fixture123',
            'checkout_attempt_id' => (string) $attempt->getKey(),
        ];
        Http::fake(function (ClientRequest $request) use ($attempt, $metadata) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/checkout/sessions') {
                return Http::response([
                    'data' => ($request->data()['status'] ?? null) === 'expired'
                        ? [['id' => 'cs_test_malformed123', 'status' => 'expired', 'customer' => null,
                            'client_reference_id' => (string) $attempt->getKey(), 'metadata' => $metadata]] : [],
                    'has_more' => false,
                ], 200);
            }
            if ($path === '/v1/checkout/sessions/cs_test_malformed123') {
                return Http::response([
                    'id' => 'cs_test_malformed123', 'mode' => 'subscription', 'status' => 'expired',
                    'client_reference_id' => (string) $attempt->getKey(), 'customer' => null,
                    'metadata' => $metadata,
                ], 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            app(ReconcileAnalyticsCheckoutAttempt::class)->handle((string) $attempt->getKey(), apply: true);
            $this->fail('A missing subscription field cannot prove an expired session has no paid subscription.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('incomplete Analytics Checkout Session', $exception->getMessage());
        }
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->provider_checkout_session_id);
    }

    public function test_operator_reconciliation_rejects_an_attempt_from_another_stripe_account(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], 'analytics-other-account-attempt-2026', 'failed');
        AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())->update([
            'created_at' => now('UTC')->subHours(13), 'provider_account_key' => 'acct_other123',
        ]);
        $this->configureBilling();
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_fixture123'], 200),
        ]);

        try {
            app(ReconcileAnalyticsCheckoutAttempt::class)->handle((string) $attempt->getKey(), apply: true);
            $this->fail('A different configured Stripe account cannot reconcile this attempt.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('another Stripe account', $exception->getMessage());
        }
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->provider_checkout_session_id);
        Http::assertSentCount(1);
    }

    public function test_same_failed_attempt_replays_with_its_stable_provider_idempotency_key(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $idempotencyKey = 'analytics-original-failed-key-2026';
        $attempt = $this->makeAttempt($fixture, $actor['platform_user'], $idempotencyKey, 'failed');
        $providerKeys = [];
        Http::fake(function (ClientRequest $request) use (&$providerKeys) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $providerKeys[] = $request->header('Idempotency-Key')[0] ?? null;

                return Http::response($this->providerCheckoutResponse($request, 'cs_test_replayfixture123'), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        $result = $this->startCheckout($fixture['analytics_workspace'], $actor['platform_user'], $idempotencyKey);

        $this->assertSame('cs_test_replayfixture123', $result['id']);
        $this->assertSame([
            'analytics-checkout-'.hash('sha256', 'checkout|'.$attempt->getKey()),
        ], $providerKeys);
    }

    public function test_completed_attempt_without_subscription_binding_blocks_a_new_checkout(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $this->makeAttempt(
            $fixture,
            $actor['platform_user'],
            'analytics-completed-unbound-key-2026',
            'completed',
            'cs_test_completedunbound123',
        );
        $checkoutPosts = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-new-after-unbound-completion-2026',
            );
            $this->fail('A completed Checkout Session without its subscription binding must remain exclusive.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, $checkoutPosts);
    }

    public function test_past_expiry_timestamp_does_not_release_open_session_without_provider_confirmation(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $attempt = $this->makeAttempt(
            $fixture,
            $actor['platform_user'],
            'analytics-expiring-open-key-2026',
            'open',
            'cs_test_expiringfixture123',
        );
        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();
        $attemptId = (string) $attempt->getKey();
        $checkoutPosts = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts, $fixture, $attemptId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions/cs_test_expiringfixture123') {
                return Http::response([
                    'id' => 'cs_test_expiringfixture123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_expiringfixture123',
                    'mode' => 'subscription',
                    'client_reference_id' => $attemptId,
                    'status' => 'open',
                    'expires_at' => now()->subMinute()->timestamp,
                    'customer' => null,
                    'metadata' => [
                        'product' => 'analytics',
                        'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
                        'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
                        'provider_account_key' => 'acct_fixture123',
                        'checkout_attempt_id' => $attemptId,
                    ],
                ], 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-new-after-local-expiry-2026',
            );
            $this->fail('An open Stripe session remains exclusive when only its local expiry timestamp has passed.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $checkoutPosts);
        $this->assertSame('open', $attempt->fresh()->status);
    }

    public function test_create_response_expired_does_not_release_attempt_before_full_retrieval(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                return Http::response($this->providerCheckoutResponse(
                    $request,
                    'cs_test_createexpiredfixture123',
                    status: 'expired',
                    expiresAt: now()->subMinute()->timestamp,
                ), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-create-expired-key-2026',
            );
            $this->fail('A create response is not enough to release an expired attempt.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }

        $attempt = AnalyticsCheckoutAttempt::query()->sole();
        $this->assertSame('open', $attempt->status);
        $this->assertSame('cs_test_createexpiredfixture123', $attempt->provider_checkout_session_id);
    }

    public function test_provider_confirmed_expiry_releases_an_unbound_checkout_attempt(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $attempt = $this->makeAttempt(
            $fixture,
            $actor['platform_user'],
            'analytics-provider-expired-key-2026',
            'open',
            'cs_test_expiredfixture123',
        );
        $attemptId = (string) $attempt->getKey();
        $newSessions = 0;
        Http::fake(function (ClientRequest $request) use (&$newSessions, $fixture, $attemptId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions/cs_test_expiredfixture123') {
                return Http::response([
                    'id' => 'cs_test_expiredfixture123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_expiredfixture123',
                    'mode' => 'subscription',
                    'client_reference_id' => $attemptId,
                    'status' => 'expired',
                    'expires_at' => now()->subMinute()->timestamp,
                    'subscription' => null,
                    'customer' => null,
                    'metadata' => [
                        'product' => 'analytics',
                        'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
                        'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
                        'provider_account_key' => 'acct_fixture123',
                        'checkout_attempt_id' => $attemptId,
                    ],
                ], 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $newSessions++;

                return Http::response($this->providerCheckoutResponse($request, 'cs_test_afterexpiryfixture123'), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-recheck-expiry-key-2026',
            );
            $this->fail('The first request must verify the old provider session before releasing it.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }
        $this->assertSame('expired', $attempt->fresh()->status);

        $result = $this->startCheckout(
            $fixture['analytics_workspace'],
            $actor['platform_user'],
            'analytics-recheck-expiry-key-2026',
        );

        $this->assertSame('cs_test_afterexpiryfixture123', $result['id']);
        $this->assertSame(1, $newSessions);
    }

    public function test_unbound_completed_history_blocks_a_new_checkout_attempt(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $this->configureBilling();
        $this->makeAttempt(
            $fixture,
            $actor['platform_user'],
            'analytics-completed-key-2026',
            'completed',
            'cs_test_completedfixture123',
            'sub_test_completedfixture123',
        );
        ProductSubscription::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(),
            'product' => 'analytics',
            'provider' => 'stripe',
            'provider_account_key' => 'acct_fixture123',
            'provider_subscription_id' => 'sub_test_completedfixture123',
            'provider_price_id' => 'price_fixture_pro',
            'plan_key' => 'pro',
            'status' => 'canceled',
            'quantity' => 1,
            'metadata' => ['plan_snapshot' => $this->plan('price_fixture_pro')['snapshot']],
        ]);

        $checkoutPosts = 0;
        Http::fake(function (ClientRequest $request) use (&$checkoutPosts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_fixture123'], 200);
            }
            if ($path === '/v1/prices/price_fixture_pro') {
                return Http::response($this->providerPrice(), 200);
            }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
                $checkoutPosts++;

                return Http::response($this->providerCheckoutResponse($request, 'cs_test_newfixture123'), 200);
            }

            return Http::response(['error' => 'Unexpected test request.'], 404);
        });

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-new-key-after-history-2026',
            );
            $this->fail('A completed attempt without an exact Core projection cannot release the purchase slot.');
        } catch (AnalyticsBillingException $exception) {
            $this->assertStringContainsString('awaiting signed subscription reconciliation', $exception->getMessage());
        }
        $this->assertSame(0, $checkoutPosts);
    }

    public function test_corrupt_current_core_plan_snapshot_blocks_checkout_before_provider_calls(): void
    {
        $fixture = $this->workspaceFixture();
        $actor = $this->addActor($fixture, role: 'billing', nativeRole: 'viewer', withGrant: true);
        $this->actingAs($actor['platform_user'], 'platform');
        $subscription = $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $metadata = $subscription->metadata;
        unset($metadata['plan_snapshot']['limits']['members']);
        $subscription->forceFill(['metadata' => $metadata])->save();
        $this->configureBilling();
        Http::fake();

        try {
            $this->startCheckout(
                $fixture['analytics_workspace'],
                $actor['platform_user'],
                'analytics-corrupt-plan-key-2026',
            );
            $this->fail('A current plan without its full immutable limits must be rejected.');
        } catch (AnalyticsBillingException) {
            $this->assertTrue(true);
        }
        Http::assertNothingSent();
    }

    public function test_sqlite_workspace_write_fence_blocks_a_competing_writer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'analytics-core-lock-');
        $this->assertNotFalse($file);
        $first = null;
        $second = null;

        try {
            $first = new PDO('sqlite:'.$file);
            $second = new PDO('sqlite:'.$file);
            $first->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $second->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $second->exec('PRAGMA busy_timeout = 25');
            $first->exec('CREATE TABLE workspaces (id TEXT PRIMARY KEY, updated_at TEXT)');
            $first->exec("INSERT INTO workspaces (id, updated_at) VALUES ('workspace-1', 'before')");

            $first->beginTransaction();
            $first->exec("UPDATE workspaces SET updated_at = 'checkout-fence' WHERE id = 'workspace-1'");

            try {
                $second->exec("UPDATE workspaces SET updated_at = 'competing-write' WHERE id = 'workspace-1'");
                $this->fail('A concurrent Core writer must wait or fail while the checkout fence is held.');
            } catch (PDOException $exception) {
                $this->assertStringContainsString('locked', strtolower($exception->getMessage()));
            }

            $first->rollBack();
            $second->exec("UPDATE workspaces SET updated_at = 'after-fence' WHERE id = 'workspace-1'");
            $this->assertSame('after-fence', $second->query("SELECT updated_at FROM workspaces WHERE id = 'workspace-1'")->fetchColumn());
        } finally {
            if ($first instanceof PDO && $first->inTransaction()) {
                $first->rollBack();
            }
            $first = null;
            $second = null;
            @unlink($file);
        }
    }

    /** @return array{analytics_workspace:AnalyticsWorkspace,core_workspace:CoreWorkspace} */
    private function workspaceFixture(): array
    {
        config(['platform.products.analytics.auth_authority' => 'core']);
        $owner = $this->platformUser('workspace-owner');
        $coreWorkspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'owner_user_id' => $owner->getKey(),
            'name' => 'Analytics billing workspace',
            'slug' => 'analytics-billing-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $analyticsWorkspace = AnalyticsWorkspace::query()->create([
            'name' => 'Analytics billing workspace',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'workspace',
            'source_id' => (string) $analyticsWorkspace->getKey(),
            'canonical_entity' => 'workspace',
            'canonical_id' => (string) $coreWorkspace->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'analytics-billing-test',
            'reconciled_at' => now(),
        ]);

        return ['analytics_workspace' => $analyticsWorkspace, 'core_workspace' => $coreWorkspace];
    }

    /** @param array{analytics_workspace:AnalyticsWorkspace,core_workspace:CoreWorkspace} $fixture
     * @return array{platform_user:PlatformUser,native_user_id:int,membership:WorkspaceMembership}
     */
    private function addActor(array $fixture, string $role, string $nativeRole, bool $withGrant): array
    {
        $platformUser = $this->platformUser('manager');
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(),
            'user_id' => $platformUser->getKey(),
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        if ($withGrant) {
            WorkspaceProductAccess::query()->create([
                'membership_id' => $membership->getKey(),
                'product' => 'analytics',
                'role' => 'billing',
                'status' => 'active',
                'granted_at' => now(),
            ]);
        }

        $nativeUserId = (int) DB::connection('analytics')->table('users')->insertGetId([
            'name' => 'Analytics billing user',
            'email' => 'analytics-billing-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'hashed-password',
            'platform_user_id' => $platformUser->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fixture['analytics_workspace']->users()->attach($nativeUserId, ['role' => $nativeRole]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $nativeUserId,
            'canonical_entity' => 'user',
            'canonical_id' => (string) $platformUser->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'analytics-billing-test',
            'reconciled_at' => now(),
        ]);

        return ['platform_user' => $platformUser, 'native_user_id' => $nativeUserId, 'membership' => $membership];
    }

    private function platformUser(string $label): PlatformUser
    {
        $email = $label.'-'.Str::lower(Str::random(8)).'@example.test';

        return PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => $label,
            'email' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
    }

    private function initializeLegacyPlanSlot(CoreWorkspace $workspace): ProductSubscription
    {
        $subscription = ProductSubscription::query()->create([
            'workspace_id' => $workspace->getKey(),
            'product' => 'analytics',
            'provider' => 'legacy_access',
            'provider_account_key' => 'analytics',
            'plan_key' => 'legacy_access',
            'status' => 'active',
            'quantity' => 1,
            'metadata' => [
                'entitlement_snapshot' => true,
                'billing_state' => ['kind' => 'no_charge_legacy_access_baseline'],
                'plan_snapshot' => [
                    'name' => 'Existing Analytics access',
                    'description' => 'No-charge migration baseline while Analytics billing tiers are designed.',
                    'entitlements' => ['*'],
                    'limits' => [
                        'sites' => null,
                        'members' => null,
                        'events_per_month' => null,
                        'retention_days' => 90,
                        'aggregate_retention_months' => 13,
                        'export_retention_hours' => 24,
                    ],
                ],
            ],
        ]);
        CurrentProductSubscription::query()->create([
            'workspace_id' => $workspace->getKey(),
            'product' => 'analytics',
            'product_subscription_id' => $subscription->getKey(),
        ]);

        return $subscription;
    }

    /** @param array{analytics_workspace:AnalyticsWorkspace,core_workspace:CoreWorkspace} $fixture
     * @return array{attempt:AnalyticsCheckoutAttempt,customer:BillingCustomer,subscription:ProductSubscription}
     */
    private function initializeCanceledAnalyticsSlot(array $fixture, PlatformUser $actor): array
    {
        $this->initializeLegacyPlanSlot($fixture['core_workspace']);
        $attempt = $this->makeAttempt(
            $fixture,
            $actor,
            'analytics-original-paid-key-2026',
            'completed',
            'cs_test_originalpaidfixture123',
            'sub_test_originalpaidfixture123',
        );
        $customer = BillingCustomer::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(),
            'provider' => 'stripe',
            'provider_account_key' => 'acct_fixture123',
            'provider_customer_id' => 'cus_test_analyticsfixture123',
            'status' => 'active',
        ]);
        $attempt->forceFill(['provider_customer_id' => $customer->provider_customer_id])->save();
        $subscription = ProductSubscription::query()->create([
            'workspace_id' => $fixture['core_workspace']->getKey(),
            'billing_customer_id' => $customer->getKey(),
            'product' => 'analytics',
            'provider' => 'stripe',
            'provider_account_key' => 'acct_fixture123',
            'provider_subscription_id' => 'sub_test_originalpaidfixture123',
            'provider_price_id' => 'price_fixture_pro',
            'plan_key' => 'pro',
            'status' => 'canceled',
            'quantity' => 1,
            'metadata' => [
                'plan_snapshot' => $this->plan('price_fixture_pro')['snapshot'],
                'billing_state' => [
                    'source' => 'analytics_stripe',
                    'checkout_attempt_id' => (string) $attempt->getKey(),
                    'cancel_at_period_end' => false,
                ],
            ],
        ]);
        CurrentProductSubscription::query()
            ->where('workspace_id', $fixture['core_workspace']->getKey())
            ->where('product', 'analytics')
            ->firstOrFail()
            ->forceFill(['product_subscription_id' => $subscription->getKey()])
            ->save();

        return ['attempt' => $attempt, 'customer' => $customer, 'subscription' => $subscription];
    }

    /** @param array{analytics_workspace:AnalyticsWorkspace,core_workspace:CoreWorkspace} $fixture
     * @param  array{attempt:AnalyticsCheckoutAttempt,customer:BillingCustomer,subscription:ProductSubscription}  $renewal
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function providerSubscriptionFor(array $fixture, array $renewal, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => (string) $renewal['subscription']->provider_subscription_id,
            'object' => 'subscription',
            'livemode' => false,
            'status' => 'canceled',
            'cancel_at_period_end' => false,
            'customer' => $renewal['customer']->provider_customer_id,
            'metadata' => [
                'product' => 'analytics',
                'core_workspace_id' => (string) $fixture['core_workspace']->getKey(),
                'analytics_workspace_id' => (string) $fixture['analytics_workspace']->getKey(),
                'provider_account_key' => 'acct_fixture123',
                'checkout_attempt_id' => (string) $renewal['attempt']->getKey(),
            ],
            'items' => ['data' => [[
                'quantity' => 1,
                'price' => ['id' => 'price_fixture_pro'],
            ]]],
        ], $overrides);
    }

    /** @param array{analytics_workspace:AnalyticsWorkspace,core_workspace:CoreWorkspace} $fixture */
    private function makeAttempt(
        array $fixture,
        PlatformUser $actor,
        string $idempotencyKey,
        string $status,
        ?string $sessionId = null,
        ?string $subscriptionId = null,
    ): AnalyticsCheckoutAttempt {
        $plan = app(AnalyticsBillingCatalog::class)->forKey('pro');
        $priceTerms = app(AnalyticsBillingCatalog::class)->priceTerms($plan);
        $priceTermsHash = app(AnalyticsBillingCatalog::class)->termsHash($plan);
        $successUrl = 'https://analytics.example/workspaces/'.$fixture['analytics_workspace']->getKey().'/billing/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = 'https://analytics.example/workspaces/'.$fixture['analytics_workspace']->getKey().'/billing/canceled';
        $fingerprint = hash('sha256', json_encode([
            'plan_key' => $plan['key'],
            'plan_snapshot' => $plan['snapshot'],
            'price_terms' => $priceTerms,
            'price_terms_hash' => $priceTermsHash,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return AnalyticsCheckoutAttempt::query()->create([
            'core_workspace_id' => $fixture['core_workspace']->getKey(),
            'analytics_workspace_id' => $fixture['analytics_workspace']->getKey(),
            'initiated_by_user_id' => $actor->getKey(),
            'provider_account_key' => 'acct_fixture123',
            'provider_checkout_session_id' => $sessionId,
            'provider_subscription_id' => $subscriptionId,
            'plan_key' => $plan['key'],
            'provider_price_id' => $plan['price_id'],
            'plan_snapshot' => $plan['snapshot'],
            'price_terms' => $priceTerms,
            'price_terms_hash' => $priceTermsHash,
            'idempotency_key_hash' => hash('sha256', $idempotencyKey),
            'request_fingerprint' => $fingerprint,
            'status' => $status,
            'expires_at' => $sessionId === null ? null : now()->addHour(),
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }

    private function configureBilling(): void
    {
        config([
            'platform.products.analytics.url' => 'https://analytics.example',
            'analytics.plan_authority' => 'core',
            'analytics.billing.enabled' => true,
            'analytics.billing.webhooks_enabled' => true,
            'analytics.billing.stripe.secret' => 'sk_test_fixture123',
            'analytics.billing.stripe.account_id' => 'acct_fixture123',
            'analytics.billing.stripe.webhook_secret' => 'whsec_fixture123',
            'analytics.billing.stripe.api_url' => 'https://api.stripe.com',
            'analytics.billing.plans' => ['pro' => [
                'name' => 'Analytics Pro',
                'price_id' => 'price_fixture_pro',
                'amount' => 1200,
                'currency' => 'USD',
                'interval' => 'month',
                'interval_count' => 1,
                'snapshot' => [
                    'name' => 'Analytics Pro',
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
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function providerPrice(): array
    {
        return [
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
        ];
    }

    /** @return array<string, mixed> */
    private function providerCheckoutResponse(
        ClientRequest $request,
        string $sessionId,
        string $status = 'open',
        ?string $customerId = null,
        ?int $expiresAt = null,
    ): array {
        $parameters = $request->data();

        return [
            'id' => $sessionId,
            'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
            'mode' => $parameters['mode'] ?? null,
            'client_reference_id' => $parameters['client_reference_id'] ?? null,
            'metadata' => $parameters['metadata'] ?? [],
            'status' => $status,
            'expires_at' => $expiresAt ?? now()->addHour()->timestamp,
            'customer' => $customerId ?? ($parameters['customer'] ?? null),
        ];
    }

    /** @return array{id:string,url:string,status:string,expires_at:int,reused:bool} */
    private function startCheckout(AnalyticsWorkspace $workspace, PlatformUser $actor, string $idempotencyKey): array
    {
        return app(AnalyticsBillingCheckout::class)->start(
            $workspace,
            $actor,
            'pro',
            $idempotencyKey,
            'https://analytics.example/workspaces/'.$workspace->getKey().'/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'https://analytics.example/workspaces/'.$workspace->getKey().'/billing/canceled',
        );
    }
}
