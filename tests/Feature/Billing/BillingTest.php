<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Accounts\InviteMember;
use App\Actions\Billing\RecordUsage;
use App\Contracts\PaymentProvider;
use App\Data\Accounts\InviteMemberData;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Enums\SelectionKind;
use App\Exceptions\AccountRuleViolation;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\UnavailablePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakePaymentProvider;
use Tests\TestCase;

final class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $stripe;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakePaymentProvider;
        $this->app->instance(PaymentProvider::class, $this->stripe);
        config([
            'billing.prices.deploy.tier' => ['starter' => 'price_deploy_starter', 'pro' => 'price_deploy_pro', 'team' => 'price_deploy_team'],
            'billing.prices.monitoring.tier' => ['pro' => 'price_mon_pro'],
        ]);
        $this->owner = User::factory()->create();
        $this->account = Account::factory()->withMember($this->owner)->create(['name' => 'Acme']);
    }

    public function test_limits_come_from_the_tiers_of_services_in_use_and_combine_generously(): void
    {
        $entitlements = app(Entitlements::class);
        $this->assertNull($entitlements->for($this->account)->limit('account.members.max'), 'No services in use, no limits.');

        Project::factory()->for($this->account)->withServices(['deploy', 'monitoring'])->create();
        $free = $entitlements->for($this->account);
        $this->assertSame(1, $free->limit('account.members.max'));
        $this->assertSame(1, $free->limit('infrastructure.servers.max'));
        $this->assertSame(500_000, $free->limit('monitoring.events.monthly'));
        $this->assertFalse($free->has('deploy.previews'));

        $this->select('deploy', 'pro');
        $this->select('monitoring', 'pro');
        $paid = $entitlements->for($this->account);
        $this->assertSame(5, $paid->limit('account.members.max'), 'Monitoring Pro allows 5 seats, Deploy Pro 1: the more generous wins.');
        $this->assertSame(5, $paid->limit('infrastructure.servers.max'));
        $this->assertTrue($paid->has('deploy.previews'));
        $this->assertFalse($paid->allows('infrastructure.servers.max', 6)->allowed);
    }

    public function test_invitations_respect_the_member_limit(): void
    {
        Notification::fake();
        Project::factory()->for($this->account)->withServices(['deploy'])->create();

        try {
            app(InviteMember::class)->handle($this->owner, $this->account, new InviteMemberData('grace@example.com', AccountRole::Member));
            $this->fail('Deploy Free allows one member.');
        } catch (AccountRuleViolation $violation) {
            $this->assertStringContainsString('Your plan allows 1', $violation->getMessage());
        }

        $this->select('deploy', 'team');
        app(InviteMember::class)->handle($this->owner, $this->account, new InviteMemberData('grace@example.com', AccountRole::Member));
        $this->assertSame(1, $this->account->invitations()->count());
    }

    public function test_the_billing_page_shows_every_service_with_prices_and_what_is_not_on_sale(): void
    {
        $this->actingAs($this->owner)->get('/account/billing')
            ->assertOk()
            ->assertSee('Deploy')->assertSee('Monitoring')->assertSee('Analytics')->assertSee('Infrastructure')
            ->assertSee('$19')->assertSee('$299')
            ->assertSee(__('Not on sale yet'));

        $viewer = User::factory()->create();
        $this->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $viewer->forceFill(['current_account_id' => $this->account->id])->save();
        $this->actingAs($viewer)->get('/account/billing')->assertForbidden();

        $member = User::factory()->create();
        $this->account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);
        $member->forceFill(['current_account_id' => $this->account->id])->save();
        $this->actingAs($member)->get('/account/billing')->assertOk()->assertDontSee(__('Switch :service plan', ['service' => 'Deploy']));
        $this->actingAs($member)->post('/account/billing/deploy', ['tier' => 'pro'])->assertForbidden();
    }

    public function test_the_first_paid_plan_goes_through_checkout_and_the_webhook_applies_it(): void
    {
        $this->actingAs($this->owner)->post('/account/billing/deploy', ['tier' => 'pro'])->assertRedirect('https://checkout.stripe.test/session');
        $this->assertSame('price_deploy_pro', $this->stripe->checkouts[0]['items'][0]->priceId);
        $this->assertSame(0, BillingSelection::query()->count(), 'Nothing changes until Stripe confirms the payment.');

        $this->stripe->completeCheckout('sub_1');
        $this->webhook('evt_1', 'checkout.session.completed', ['client_reference_id' => $this->account->id, 'customer' => 'cus_x', 'subscription' => 'sub_1'])->assertOk();
        $this->webhook('evt_1', 'checkout.session.completed', ['client_reference_id' => $this->account->id, 'customer' => 'cus_x', 'subscription' => 'sub_1'])->assertOk();

        $billing = BillingAccount::query()->findOrFail($this->account->id);
        $this->assertSame(['sub_1', 'active'], [$billing->stripe_subscription_id, $billing->status]);
        $this->assertSame('pro', BillingSelection::query()->sole()->item_key);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::PlanChanged)->count(), 'Webhook retries apply once.');
    }

    public function test_changing_one_service_only_changes_its_item_and_downgrades_wait_for_the_period_end(): void
    {
        $this->subscribe(['deploy' => 'starter', 'monitoring' => 'pro']);

        $this->actingAs($this->owner)->post('/account/billing/deploy', ['tier' => 'team'])->assertRedirect('/account/billing')->assertSessionHas('status');
        $this->assertSame(['price_deploy_team', 'price_mon_pro'], array_map(fn ($item) => $item->priceId, $this->stripe->subscriptions['sub_1']));

        $this->actingAs($this->owner)->post('/account/billing/monitoring', ['tier' => 'free'])->assertRedirect('/account/billing');
        $monitoring = BillingSelection::query()->where('service', 'monitoring')->sole();
        $this->assertNotNull($monitoring->ends_at, 'Paid until the end of the period.');
        $this->assertCount(2, $this->stripe->subscriptions['sub_1']);

        $this->actingAs($this->owner)->post('/account/billing/monitoring/resume')->assertRedirect('/account/billing');
        $this->assertNull($monitoring->refresh()->ends_at);

        $this->actingAs($this->owner)->post('/account/billing/monitoring', ['tier' => 'free']);
        $this->travel(32)->days();
        Artisan::call('billing:apply-ended');
        $this->assertFalse(BillingSelection::query()->where('service', 'monitoring')->exists());
        $this->assertSame(['price_deploy_team'], array_map(fn ($item) => $item->priceId, $this->stripe->subscriptions['sub_1']));
    }

    public function test_unknown_or_unpriced_tiers_and_missing_payments_are_refused(): void
    {
        $this->actingAs($this->owner)->post('/account/billing/deploy', ['tier' => 'platinum'])->assertSessionHasErrors('tier');
        $this->actingAs($this->owner)->post('/account/billing/deploy', ['tier' => 'unlimited'])->assertSessionHasErrors('tier');
        $this->actingAs($this->owner)->post('/account/billing/nope', ['tier' => 'pro'])->assertSessionHasErrors('tier');

        $this->app->instance(PaymentProvider::class, new UnavailablePaymentProvider);
        $this->actingAs($this->owner)->post('/account/billing/deploy', ['tier' => 'pro'])->assertSessionHasErrors('tier');
    }

    public function test_webhooks_must_be_signed_and_a_deleted_subscription_drops_paid_plans(): void
    {
        $this->subscribe(['deploy' => 'pro']);
        $this->postJson('/webhooks/stripe', ['id' => 'evt_x', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_1']]], ['Stripe-Signature' => 'forged'])->assertStatus(400);

        $this->webhook('evt_2', 'customer.subscription.deleted', ['id' => 'sub_1'])->assertOk();
        $this->assertSame(0, BillingSelection::query()->count());
        $this->assertSame('canceled', BillingAccount::query()->findOrFail($this->account->id)->status);
    }

    public function test_usage_is_bucketed_by_hour_and_shown_against_the_allowance(): void
    {
        Project::factory()->for($this->account)->withServices(['monitoring'])->create();
        $record = app(RecordUsage::class);
        $record->handle($this->account->id, 'monitoring.events', 1200);
        $record->handle($this->account->id, 'monitoring.events', 300);

        $this->assertSame(1500, (int) \App\Models\UsageRecord::query()->sole()->quantity);
        $this->actingAs($this->owner)->get('/account/billing')->assertOk()->assertSee('1,500 / 500,000 events');
    }

    private function select(string $service, string $tier): void
    {
        (new BillingSelection)->forceFill(['account_id' => $this->account->id, 'service' => $service, 'kind' => SelectionKind::Tier, 'item_key' => $tier])->save();
    }

    /** @param array<string, string> $tiers */
    private function subscribe(array $tiers): void
    {
        foreach ($tiers as $service => $tier) {
            $this->select($service, $tier);
        }
        (new BillingAccount)->forceFill(['account_id' => $this->account->id, 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'status' => 'active', 'current_period_end' => now()->addMonth()])->save();
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function webhook(string $id, string $type, array $object): TestResponse
    {
        return $this->postJson('/webhooks/stripe', ['id' => $id, 'type' => $type, 'data' => ['object' => $object]], ['Stripe-Signature' => 'valid']);
    }
}
