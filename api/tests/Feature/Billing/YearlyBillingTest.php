<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\PaymentProvider;
use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePaymentProvider;
use Tests\TestCase;

final class YearlyBillingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check yearly billing: the pricing page shows yearly prices, checkout uses the yearly price, and a live
     * subscription switches interval only when every plan has a price for it.
     *
     * @return void
     */
    public function test_accounts_can_pay_yearly_with_two_months_free(): void
    {
        $stripe = new FakePaymentProvider;
        $this->app->instance(PaymentProvider::class, $stripe);
        config([
            'billing.prices.deploy.tier' => ['pro' => 'price_pro_monthly', 'team' => 'price_team_monthly'],
            'billing.prices_yearly.deploy.tier' => ['pro' => 'price_pro_yearly'],
            'billing.trial_days' => 0,
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();

        $this->get('/pricing?billing=yearly')->assertOk()->assertSee('Yearly · 2 months free')->assertSee('$190')->assertSee('/ year');
        $this->actingAs($owner)->get('/account/billing')->assertOk()->assertSee('Pay yearly, 2 months free');

        $this->actingAs($owner)->put('/account/billing/interval', ['interval' => 'year'])->assertRedirect('/account/billing');
        $this->assertSame('year', BillingAccount::query()->findOrFail($account->id)->interval);
        $this->actingAs($owner)->post('/account/billing/deploy', ['tier' => 'pro'])->assertRedirect('https://checkout.stripe.test/session');
        $this->assertSame('price_pro_yearly', $stripe->checkouts[0]['items'][0]->priceId);
        $this->actingAs($owner)->post('/account/billing/deploy', ['tier' => 'team'])->assertSessionHasErrors();

        // A live monthly subscription with Team (no yearly price) can't switch; with Pro it moves in place.
        $billing = BillingAccount::query()->findOrFail($account->id);
        $billing->forceFill(['interval' => 'month', 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'status' => 'active', 'current_period_end' => now()->addMonth()])->save();
        $selection = (new BillingSelection)->forceFill(['account_id' => $account->id, 'service' => 'deploy', 'kind' => SelectionKind::Tier, 'item_key' => 'team']);
        $selection->save();
        $this->actingAs($owner)->put('/account/billing/interval', ['interval' => 'year'])->assertSessionHasErrors('interval');
        $selection->forceFill(['item_key' => 'pro'])->save();
        $this->actingAs($owner)->put('/account/billing/interval', ['interval' => 'year'])->assertRedirect();
        $this->assertSame('price_pro_yearly', $stripe->subscriptions['sub_1'][0]->priceId);
        $this->actingAs($owner)->get('/account/billing')->assertOk()->assertSee('You pay yearly')->assertSee('$190');
    }
}
