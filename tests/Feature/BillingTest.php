<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_page_is_public(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('Simple pricing')
            ->assertSee('$9')
            ->assertSee('$19')
            ->assertSee('$49')
            ->assertSee('$99')
            ->assertSee('$199')
            ->assertSee('Unlimited servers')
            ->assertSee('fair-use policy');
    }

    public function test_billing_page_requires_authentication(): void
    {
        $this->get(route('billing.index'))->assertRedirect(route('login'));
    }

    public function test_free_user_sees_billing_page_and_configuration_notice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Current plan')
            ->assertSee('Free')
            ->assertSee('Payments are almost ready');
    }

    public function test_checkout_rejects_unknown_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('billing.checkout', 'unknown'))->assertNotFound();
    }

    public function test_checkout_stays_unavailable_until_stripe_is_configured(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('billing.checkout', 'pro'))->assertStatus(503);
    }

    public function test_yearly_subscription_is_detected_as_the_workspace_plan(): void
    {
        config(['billing.plans.pro.yearly_price_id' => 'price_pro_year']);
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_year', 'stripe_status' => 'active', 'stripe_price' => 'price_pro_year']);
        $subscription->items()->create(['stripe_id' => 'si_year', 'stripe_product' => 'prod_pro', 'stripe_price' => 'price_pro_year']);

        $this->assertSame('pro', $user->billingPlan());
        $this->assertSame('yearly', $user->billingInterval());
    }

    public function test_non_owner_cannot_change_workspace_billing(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $owner->currentOrganization->members()->attach($member, ['role' => 'admin']);
        $member->update(['current_organization_id' => $owner->current_organization_id]);
        config(['cashier.secret' => 'sk_test', 'billing.plans.pro.monthly_price_id' => 'price_pro']);

        $this->actingAs($member)->post(route('billing.checkout', 'pro'), ['interval' => 'monthly'])->assertForbidden();
    }
}
