<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\PaymentProvider;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePaymentProvider;
use Tests\TestCase;

final class PlanLimitsTest extends TestCase
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
        config(['billing.prices.deploy.tier' => ['pro' => 'price_deploy_pro'], 'billing.trial_days' => 14]);
        $this->owner = User::factory()->create();
        $this->account = Account::factory()->withMember($this->owner)->create();
        $this->owner->forceFill(['current_account_id' => $this->account->id])->save();
        Project::factory()->for($this->account)->withServices(['deploy', 'infrastructure', 'analytics'])->create();
    }

    public function test_the_first_paid_plan_starts_with_a_trial_and_later_ones_do_not(): void
    {
        $this->actingAs($this->owner)->get('/account/billing')->assertOk()->assertSee('14-day free trial');
        $this->get('/pricing')->assertSee('Your first paid plan is free for 14 days');

        $this->actingAs($this->owner)->post('/account/billing/deploy', ['tier' => 'pro'])->assertRedirect('https://checkout.stripe.test/session');
        $this->assertSame(14, $this->stripe->checkouts[0]['trial_days']);

        BillingAccount::forAccount($this->account->id)->forceFill(['status' => 'canceled', 'stripe_subscription_id' => 'sub_old'])->save();
        $this->actingAs($this->owner)->get('/account/billing')->assertDontSee('14-day free trial');
    }

    public function test_billing_shows_every_limit_and_the_app_warns_before_a_monthly_allowance_runs_out(): void
    {
        Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->account->id])->id]);
        $this->actingAs($this->owner)->get('/account/billing')->assertOk()->assertSee('Plan limits')->assertSee('Servers')->assertSee('1 / 1')->assertSee('Limit reached.');
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertDontSee('See plans');

        $usage = new UsageRecord;
        $usage->forceFill(['account_id' => $this->account->id, 'meter' => 'analytics.pageviews', 'quantity' => 9_000, 'period_start' => now()->utc()->startOfHour()])->save();
        cache()->flush();
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee('You’ve used 9,000 of 10,000 pageviews on your plan this month.', false)->assertSee(route('account.billing').'#billing-analytics');

        // With a plan on sale that raises the allowance, the warning names it.
        config(['billing.prices.analytics.tier' => ['pro' => 'price_analytics_pro', 'business' => 'price_analytics_business']]);
        cache()->flush();
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee('Analytics Pro gives 100,000 pageviews for $9 a month.')->assertSee('Upgrade');
        $this->actingAs($this->owner)->get('/account/billing')->assertOk()->assertSee('Pro gives 100,000 for $9/mo');
    }

    public function test_hitting_a_limit_offers_the_plans_with_more(): void
    {
        $this->actingAs($this->owner);
        $this->withViewErrors(['plan' => 'Your plan allows 1 server.'])->blade('<x-signal.ui.plan-limit-alert service="infrastructure" />')
            ->assertSee('Your plan allows 1 server.')->assertSee('See plans with more')->assertSee(route('account.billing').'#billing-infrastructure');
        $this->withViewErrors([])->blade('<x-signal.ui.plan-limit-alert />')->assertDontSee('See plans');
    }
}
