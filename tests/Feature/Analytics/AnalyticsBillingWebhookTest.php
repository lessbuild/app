<?php

namespace Tests\Feature\Analytics;

use App\Core\Enums\ProductKey;
use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Billing\ResolveProductPlan;
use App\Core\Services\Deletion\DeletionPlanner;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCatalog;
use App\Modules\Analytics\Services\Billing\AnalyticsStripeWebhookVerifier;
use App\Modules\Analytics\Services\Billing\ProcessAnalyticsBillingWebhook;
use App\Modules\Analytics\Services\Billing\ReconcileAnalyticsBillingEvents;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsBillingWebhookTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'analytics.billing.webhooks_enabled' => true,
            'analytics.billing.enabled' => false,
            'analytics.billing.stripe.secret' => 'sk_test_analyticsfixture',
            'analytics.billing.stripe.account_id' => 'acct_analyticsfixture',
            'analytics.billing.stripe.webhook_secret' => 'whsec_analyticsfixture',
            'analytics.billing.stripe.api_url' => 'https://api.stripe.com',
            'analytics.billing.plans' => ['pro' => $this->plan('price_analyticspro', 2)],
        ]);

        Http::preventStrayRequests();
    }

    public function test_verified_subscription_changes_only_analytics_slot_and_replay_is_idempotent(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $other = ProductSubscription::query()->create([
            'workspace_id' => $core->getKey(),
            'product' => 'deployer',
            'provider' => 'legacy',
            'provider_account_key' => 'deployer',
            'plan_key' => 'starter',
            'status' => 'active',
            'metadata' => ['plan_snapshot' => ['name' => 'Starter', 'entitlements' => ['*'], 'limits' => []]],
        ]);
        CurrentProductSubscription::query()->create([
            'workspace_id' => $core->getKey(),
            'product' => 'deployer',
            'product_subscription_id' => $other->getKey(),
        ]);

        $subscription = $this->subscription($core, $native, $attempt);
        $event = $this->event('evt_analyticsactive', 'customer.subscription.created');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt));

        $this->assertTrue(app(ProcessAnalyticsBillingWebhook::class)->handle($event));
        $this->assertFalse(app(ProcessAnalyticsBillingWebhook::class)->handle($event));

        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame('stripe', $current->provider);
        $this->assertSame('acct_analyticsfixture', $current->provider_account_key);
        $this->assertSame('pro', $current->plan_key);
        $this->assertSame(2, $current->metadata['plan_snapshot']['limits']['sites']);
        $this->assertSame($other->getKey(), CurrentProductSubscription::query()
            ->where('workspace_id', $core->getKey())->where('product', 'deployer')->value('product_subscription_id'));
        $this->assertTrue(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
        $this->assertSame(1, ProductBillingEvent::query()->where('provider_event_id', $event['id'])->count());
    }

    public function test_checkout_time_snapshot_survives_catalog_change_before_first_webhook(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $revised = $this->plan('price_analyticspro', 2);
        $revised['snapshot']['limits']['sites'] = 9;
        config(['analytics.billing.plans' => ['pro' => $revised]]);
        $subscription = $this->subscription($core, $native, $attempt);
        $event = $this->event('evt_analyticssnapshot', 'customer.subscription.created');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt));

        app(ProcessAnalyticsBillingWebhook::class)->handle($event);

        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame(2, $current->metadata['plan_snapshot']['limits']['sites']);
    }

    public function test_cancellation_and_older_same_second_event_cannot_restore_legacy_access(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $active = $this->event('evt_analyticsactive', 'customer.subscription.created');
        $canceled = $this->event('evt_analyticscanceled', 'customer.subscription.deleted');
        $older = $this->event('evt_analyticsolder', 'customer.subscription.updated');
        $session = $this->session($core, $native, $attempt);
        $this->fakeStripe($subscription, [
            $active['id'] => $active,
            $canceled['id'] => $canceled,
            $older['id'] => $older,
        ], $session);

        app(ProcessAnalyticsBillingWebhook::class)->handle($active);
        $subscription['status'] = 'canceled';
        $subscription['canceled_at'] = now('UTC')->timestamp;
        app(ProcessAnalyticsBillingWebhook::class)->handle($canceled);
        app(ProcessAnalyticsBillingWebhook::class)->handle($older);

        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame('stripe', $current->provider);
        $this->assertSame('canceled', $current->status);
        $this->assertFalse(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
        $this->assertSame(1, CurrentProductSubscription::query()
            ->where('workspace_id', $core->getKey())->where('product', 'analytics')->count());
    }

    public function test_first_observed_completed_checkout_already_canceled_replaces_legacy_slot_with_denied_paid_state(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $subscription['status'] = 'canceled';
        $subscription['canceled_at'] = now('UTC')->timestamp;
        $event = $this->event('evt_analyticsfirstcanceled', 'customer.subscription.deleted');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt));

        app(ProcessAnalyticsBillingWebhook::class)->handle($event);

        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame('stripe', $current->provider);
        $this->assertSame('canceled', $current->status);
        $this->assertFalse(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
    }

    public function test_projection_uses_provider_state_fetched_after_serialization_lock(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $stale = $subscription;
        $subscription['status'] = 'canceled';
        $subscription['canceled_at'] = now('UTC')->timestamp;
        $event = $this->event('evt_analyticsstale', 'customer.subscription.updated');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt), $stale);

        app(ProcessAnalyticsBillingWebhook::class)->handle($event);

        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame('canceled', $current->status);
        $this->assertFalse(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
    }

    public function test_unresolved_checkout_attempt_blocks_workspace_deletion_until_provider_confirmed_expiry(): void
    {
        [$core, , $attempt] = $this->workspaceAndAttempt();

        $this->assertContains('pending_billing_reconciliation', app(DeletionPlanner::class)->billingBlockers((string) $core->getKey()));

        $attempt->forceFill(['status' => 'expired'])->save();
        $this->assertSame([], app(DeletionPlanner::class)->billingBlockers((string) $core->getKey()));
    }

    public function test_unknown_provider_price_suspends_paid_entitlement_and_pending_receipt_can_recover(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $active = $this->event('evt_analyticsactive', 'customer.subscription.created');
        $changed = $this->event('evt_analyticschanged', 'customer.subscription.updated');
        $this->fakeStripe($subscription, [$active['id'] => $active, $changed['id'] => $changed],
            $this->session($core, $native, $attempt));

        app(ProcessAnalyticsBillingWebhook::class)->handle($active);
        $subscription['items']['data'][0]['price']['id'] = 'price_analyticsunknown';

        try {
            app(ProcessAnalyticsBillingWebhook::class)->handle($changed);
            $this->fail('An unallowlisted provider price must remain unreconciled.');
        } catch (RuntimeException) {
            // A non-2xx webhook response asks Stripe to retry this pending receipt.
        }

        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame('unverified_price', $current->status);
        $this->assertSame('superseded', ProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->where('provider_price_id', 'price_analyticspro')->firstOrFail()->status);
        $this->assertFalse(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
        $this->assertSame('pending', ProductBillingEvent::query()->where('provider_event_id', $changed['id'])->value('processing_status'));

        config(['analytics.billing.plans' => [
            'pro' => $this->plan('price_analyticspro', 2),
            'team' => $this->plan('price_analyticsunknown', 5),
        ]]);
        $summary = app(ReconcileAnalyticsBillingEvents::class)->handle(10, $changed['id']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame('team', CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription->plan_key);
    }

    public function test_first_observed_subscription_with_unknown_price_suspends_legacy_access(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $subscription['items']['data'][0]['price']['id'] = 'price_unapproved';
        $event = $this->event('evt_analyticsunapprovedfirst', 'customer.subscription.updated');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt));

        try {
            app(ProcessAnalyticsBillingWebhook::class)->handle($event);
            $this->fail('An unapproved price must remain pending.');
        } catch (RuntimeException) {
            $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
                ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
            $this->assertSame('stripe', $current->provider);
            $this->assertSame('unverified_price', $current->status);
            $this->assertFalse(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
        }
    }

    public function test_provider_price_changed_to_tiered_billing_cannot_grant_analytics_entitlement(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $event = $this->event('evt_analyticstiered', 'customer.subscription.created');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt), null,
            ['billing_scheme' => 'tiered']);

        try {
            app(ProcessAnalyticsBillingWebhook::class)->handle($event);
            $this->fail('A tiered price must not grant a fixed Analytics plan.');
        } catch (RuntimeException) {
            $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
                ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
            $this->assertSame('unverified_price', $current->status);
            $this->assertFalse(app(ResolveProductPlan::class)->resolve((string) $core->getKey(), ProductKey::Analytics)->available);
        }
    }

    public function test_portal_price_change_preserves_snapshot_history_without_permanent_deletion_blocker(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        config(['analytics.billing.plans' => [
            'pro' => $this->plan('price_analyticspro', 2),
            'team' => $this->plan('price_analyticsteam', 5),
        ]]);
        $subscription = $this->subscription($core, $native, $attempt);
        $active = $this->event('evt_analyticsactive', 'customer.subscription.created');
        $changed = $this->event('evt_analyticspricechange', 'customer.subscription.updated');
        $canceled = $this->event('evt_analyticsend', 'customer.subscription.deleted');
        $this->fakeStripe($subscription, [
            $active['id'] => $active,
            $changed['id'] => $changed,
            $canceled['id'] => $canceled,
        ], $this->session($core, $native, $attempt));

        app(ProcessAnalyticsBillingWebhook::class)->handle($active);
        $subscription['items']['data'][0]['price']['id'] = 'price_analyticsteam';
        app(ProcessAnalyticsBillingWebhook::class)->handle($changed);

        $old = ProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->where('provider_price_id', 'price_analyticspro')->firstOrFail();
        $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
            ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
        $this->assertSame('superseded', $old->status);
        $this->assertSame(2, $old->metadata['plan_snapshot']['limits']['sites']);
        $this->assertSame('price_analyticsteam', $current->provider_price_id);
        $this->assertSame(5, $current->metadata['plan_snapshot']['limits']['sites']);

        $subscription['status'] = 'canceled';
        $subscription['current_period_end'] = now('UTC')->subDay()->timestamp;
        $subscription['canceled_at'] = now('UTC')->timestamp;
        app(ProcessAnalyticsBillingWebhook::class)->handle($canceled);

        $this->assertSame([], app(DeletionPlanner::class)->billingBlockers((string) $core->getKey()));
        $this->assertSame('superseded', $old->fresh()->status);
    }

    public function test_signature_and_key_mode_are_required_before_projection(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $event = $this->event('evt_analyticslive', 'customer.subscription.created');
        $event['livemode'] = true;
        $subscription = $this->subscription($core, $native, $attempt);
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt));

        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now('UTC')->timestamp;
        $valid = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_analyticsfixture');
        $this->assertSame($event['id'], app(AnalyticsStripeWebhookVerifier::class)->verify($payload, $valid)['id']);
        $this->expectException(RuntimeException::class);
        app(ProcessAnalyticsBillingWebhook::class)->handle($event);
    }

    public function test_invalid_webhook_signature_is_rejected_without_a_receipt(): void
    {
        $event = $this->event('evt_analyticsforged', 'customer.subscription.created');
        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        try {
            app(AnalyticsStripeWebhookVerifier::class)->verify(
                $payload,
                't='.now('UTC')->timestamp.',v1='.str_repeat('0', 64),
            );
            $this->fail('An invalid Stripe signature must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, ProductBillingEvent::query()->where('provider_event_id', $event['id'])->count());
        }
    }

    public function test_unavailable_provider_event_is_deferred_without_starving_later_receipts(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $subscription = $this->subscription($core, $native, $attempt);
        $ready = $this->event('evt_analyticsready', 'customer.subscription.updated');
        $this->fakeStripe($subscription, [$ready['id'] => $ready], $this->session($core, $native, $attempt));
        foreach (['evt_analyticsmissing', $ready['id']] as $eventId) {
            ProductBillingEvent::query()->create([
                'workspace_id' => $core->getKey(),
                'product' => 'analytics',
                'provider' => 'stripe',
                'provider_account_key' => 'acct_analyticsfixture',
                'provider_event_id' => $eventId,
                'event_type' => 'customer.subscription.updated',
                'processing_status' => 'pending',
            ]);
        }
        ProductBillingEvent::query()->where('provider_event_id', 'evt_analyticsmissing')
            ->update(['updated_at' => now('UTC')->subMinute()]);

        $first = app(ReconcileAnalyticsBillingEvents::class)->handle(1);
        $this->assertSame(1, $first['failed']);
        $this->assertTrue(ProductBillingEvent::query()->where('provider_event_id', 'evt_analyticsmissing')
            ->firstOrFail()->updated_at->isFuture());

        $second = app(ReconcileAnalyticsBillingEvents::class)->handle(1);
        $this->assertSame(1, $second['completed']);
        $this->assertSame('applied', ProductBillingEvent::query()->where('provider_event_id', $ready['id'])->value('processing_status'));
    }

    public function test_customer_bound_to_another_core_workspace_cannot_replace_legacy_plan(): void
    {
        [$core, $native, $attempt] = $this->workspaceAndAttempt();
        $other = CoreWorkspace::query()->create([
            'owner_user_id' => $core->owner_user_id,
            'name' => 'Other workspace',
            'slug' => 'other-analytics-workspace',
            'status' => 'active',
        ]);
        BillingCustomer::query()->create([
            'workspace_id' => $other->getKey(),
            'provider' => 'stripe',
            'provider_account_key' => 'acct_analyticsfixture',
            'provider_customer_id' => 'cus_analyticsfixture',
            'status' => 'active',
        ]);

        $subscription = $this->subscription($core, $native, $attempt);
        $event = $this->event('evt_analyticswrongcustomer', 'customer.subscription.created');
        $this->fakeStripe($subscription, [$event['id'] => $event], $this->session($core, $native, $attempt));

        try {
            app(ProcessAnalyticsBillingWebhook::class)->handle($event);
            $this->fail('A customer already bound to another workspace must not be reassigned.');
        } catch (RuntimeException) {
            $current = CurrentProductSubscription::query()->where('workspace_id', $core->getKey())
                ->where('product', 'analytics')->with('subscription')->firstOrFail()->subscription;
            $this->assertSame('legacy_access', $current->provider);
            $this->assertSame('pending', ProductBillingEvent::query()
                ->where('provider_event_id', $event['id'])->value('processing_status'));
        }
    }

    /** @return array{CoreWorkspace,AnalyticsWorkspace,AnalyticsCheckoutAttempt} */
    private function workspaceAndAttempt(): array
    {
        $owner = PlatformUser::query()->create(['name' => 'Billing owner', 'email' => 'billing@example.test', 'status' => 'active']);
        $core = CoreWorkspace::query()->create([
            'owner_user_id' => $owner->getKey(),
            'name' => 'Analytics billing',
            'slug' => 'analytics-billing',
            'status' => 'active',
        ]);
        $native = AnalyticsWorkspace::query()->create(['name' => 'Analytics billing']);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'workspace',
            'source_id' => (string) $native->getKey(),
            'canonical_entity' => 'workspace',
            'canonical_id' => (string) $core->getKey(),
            'status' => 'reconciled',
        ]);
        $legacy = ProductSubscription::query()->create([
            'workspace_id' => $core->getKey(),
            'product' => 'analytics',
            'provider' => 'legacy_access',
            'provider_account_key' => 'analytics',
            'plan_key' => 'legacy_access',
            'status' => 'active',
            'metadata' => ['plan_snapshot' => ['name' => 'Legacy', 'entitlements' => ['*'], 'limits' => []]],
        ]);
        CurrentProductSubscription::query()->create([
            'workspace_id' => $core->getKey(),
            'product' => 'analytics',
            'product_subscription_id' => $legacy->getKey(),
        ]);
        $approvedPlan = $this->plan('price_analyticspro', 2);
        $catalog = app(AnalyticsBillingCatalog::class);
        $attempt = AnalyticsCheckoutAttempt::query()->create([
            'core_workspace_id' => $core->getKey(),
            'analytics_workspace_id' => (string) $native->getKey(),
            'initiated_by_user_id' => (string) $owner->getKey(),
            'provider_account_key' => 'acct_analyticsfixture',
            'provider_checkout_session_id' => 'cs_test_analyticsfixture',
            'plan_key' => 'pro',
            'provider_price_id' => 'price_analyticspro',
            'plan_snapshot' => $approvedPlan['snapshot'],
            'price_terms' => $catalog->priceTerms($approvedPlan),
            'price_terms_hash' => $catalog->termsHash($approvedPlan),
            'idempotency_key_hash' => hash('sha256', 'billing-fixture-request'),
            'request_fingerprint' => hash('sha256', 'billing-fixture-terms'),
            'status' => 'open',
        ]);

        return [$core, $native, $attempt];
    }

    /** @return array<string, mixed> */
    private function plan(string $priceId, int $siteLimit): array
    {
        return [
            'name' => $siteLimit === 2 ? 'Pro' : 'Team',
            'description' => 'Analytics workspace plan',
            'price_id' => $priceId,
            'amount' => $siteLimit === 2 ? 2900 : 5900,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'snapshot' => [
                'name' => $siteLimit === 2 ? 'Pro' : 'Team',
                'entitlements' => ['site_management', 'event_collection'],
                'limits' => [
                    'sites' => $siteLimit,
                    'members' => 10,
                    'events_per_month' => 100000,
                    'retention_days' => 90,
                    'aggregate_retention_months' => 13,
                    'export_retention_hours' => 24,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function subscription(CoreWorkspace $core, AnalyticsWorkspace $native, AnalyticsCheckoutAttempt $attempt): array
    {
        return [
            'id' => 'sub_analyticsfixture',
            'object' => 'subscription',
            'livemode' => false,
            'customer' => 'cus_analyticsfixture',
            'status' => 'active',
            'metadata' => $this->metadata($core, $native, $attempt),
            'current_period_start' => now('UTC')->timestamp,
            'current_period_end' => now('UTC')->addMonth()->timestamp,
            'items' => ['data' => [['price' => ['id' => 'price_analyticspro'], 'quantity' => 1]]],
        ];
    }

    /** @return array<string, mixed> */
    private function session(CoreWorkspace $core, AnalyticsWorkspace $native, AnalyticsCheckoutAttempt $attempt): array
    {
        return [
            'id' => 'cs_test_analyticsfixture',
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'status' => 'complete',
            'customer' => 'cus_analyticsfixture',
            'subscription' => 'sub_analyticsfixture',
            'metadata' => $this->metadata($core, $native, $attempt),
        ];
    }

    /** @return array<string, string> */
    private function metadata(CoreWorkspace $core, AnalyticsWorkspace $native, AnalyticsCheckoutAttempt $attempt): array
    {
        return [
            'product' => 'analytics',
            'core_workspace_id' => (string) $core->getKey(),
            'analytics_workspace_id' => (string) $native->getKey(),
            'provider_account_key' => 'acct_analyticsfixture',
            'checkout_attempt_id' => (string) $attempt->getKey(),
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => now('UTC')->timestamp,
            'data' => ['object' => ['id' => 'sub_analyticsfixture']],
        ];
    }

    /** @param array<string, mixed> $subscription
     * @param  array<string, array<string, mixed>>  $events
     * @param  array<string, mixed>  $session
     */
    private function fakeStripe(
        array &$subscription,
        array $events,
        array $session,
        ?array $firstSubscription = null,
        ?array $priceOverrides = null,
    ): void {
        $subscriptionReads = 0;
        Http::fake(function (ClientRequest $request) use (&$subscription, $events, $session, $firstSubscription, $priceOverrides, &$subscriptionReads) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/subscriptions/sub_analyticsfixture') {
                $subscriptionReads++;

                return Http::response($subscriptionReads === 1 && $firstSubscription !== null ? $firstSubscription : $subscription, 200);
            }
            if (str_starts_with((string) $path, '/v1/prices/')) {
                $priceId = basename((string) $path);
                $plan = $this->plan($priceId, $priceId === 'price_analyticspro' ? 2 : 5);

                return Http::response(array_replace([
                    'id' => $priceId,
                    'active' => true,
                    'livemode' => false,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'transform_quantity' => null,
                    'unit_amount' => $plan['amount'],
                    'currency' => $plan['currency'],
                    'recurring' => [
                        'interval' => $plan['interval'],
                        'interval_count' => $plan['interval_count'],
                        'usage_type' => 'licensed',
                    ],
                ], $priceOverrides ?? []), 200);
            }

            return match ($path) {
                '/v1/account' => Http::response(['id' => 'acct_analyticsfixture'], 200),
                '/v1/checkout/sessions/cs_test_analyticsfixture' => Http::response($session, 200),
                default => str_starts_with((string) $path, '/v1/events/')
                    ? Http::response($events[basename((string) $path)] ?? [], isset($events[basename((string) $path)]) ? 200 : 404)
                    : Http::response([], 404),
            };
        });
    }
}
