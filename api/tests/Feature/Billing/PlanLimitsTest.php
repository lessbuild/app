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

    /**
     * The first paid plan starts with a trial and later ones do not.
     */
    public function test_the_first_paid_plan_starts_with_a_trial_and_later_ones_do_not(): void
    {
        $this->actingAs($this->owner)->getJson('/api/app/account/billing')->assertOk()->assertJsonPath('trialDays', 14);

        $this->actingAs($this->owner)->postJson('/api/app/account/billing/deploy', ['tier' => 'pro'])->assertOk()->assertJsonPath('redirect', 'https://checkout.stripe.test/session');
        $this->assertSame(14, $this->stripe->checkouts[0]['trial_days']);

        BillingAccount::forAccount($this->account->id)->forceFill(['status' => 'canceled', 'stripe_subscription_id' => 'sub_old'])->save();
        $this->actingAs($this->owner)->getJson('/api/app/account/billing')->assertJsonPath('trialDays', 0);
    }

    /**
     * Billing shows every limit and the app warns before a monthly allowance runs out.
     */
    public function test_billing_shows_every_limit_and_the_app_warns_before_a_monthly_allowance_runs_out(): void
    {
        Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->account->id])->id]);
        $servers = $this->limit('infrastructure.servers.max');
        $this->assertSame([1, 1, 100], [$servers['used'], $servers['limit'], $servers['percent']]);
        $this->actingAs($this->owner)->getJson('/api/app/shell')->assertOk()->assertJsonPath('limitWarning', null);

        $usage = new UsageRecord;
        $usage->forceFill(['account_id' => $this->account->id, 'meter' => 'analytics.pageviews', 'quantity' => 9_000, 'period_start' => now()->utc()->startOfHour()])->save();
        cache()->flush();
        $this->actingAs($this->owner)->getJson('/api/app/shell')->assertOk()->assertJsonPath('limitWarning.message', 'You’ve used 9,000 of 10,000 pageviews on your plan this month.')->assertJsonPath('limitWarning.url', '/account/billing?tab=analytics');

        // With a plan on sale that raises the allowance, the warning names it.
        config(['billing.prices.analytics.tier' => ['pro' => 'price_analytics_pro', 'business' => 'price_analytics_business']]);
        cache()->flush();
        $this->actingAs($this->owner)->getJson('/api/app/shell')->assertOk()->assertSee('Analytics Pro gives 100,000 pageviews for $9 a month.')->assertJsonPath('limitWarning.linkLabel', 'Upgrade');
        $this->assertSame(['tier' => 'Pro', 'limit' => 100000, 'monthlyCents' => 900], $this->limit('analytics.pageviews.monthly')['upgrade']);
    }

    /**
     * Read one of the account's limits from the billing page's data.
     *
     * @param  string  $key
     * @return array<string, mixed>
     */
    private function limit(string $key): array
    {
        foreach ((array) $this->actingAs($this->owner)->getJson('/api/app/account/billing')->assertOk()->json('limits') as $limit) {
            if (is_array($limit) && $limit['key'] === $key) {
                return $limit;
            }
        }
        $this->fail("No {$key} limit.");
    }
}
