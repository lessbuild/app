<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiPlanAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['billing.enforce_entitlements' => true]);
    }

    public function test_every_plan_includes_api_access_and_a_finite_request_limit(): void
    {
        foreach ([
            'free' => 60,
            'starter' => 120,
            'pro' => 300,
            'team' => 600,
            'business' => 1200,
            'unlimited' => 3000,
        ] as $plan => $limit) {
            $entitlements = config("billing.plans.{$plan}.entitlements");
            $this->assertTrue(in_array('api', $entitlements, true) || in_array('*', $entitlements, true), $plan.' must include API access.');
            $this->assertSame($limit, config("billing.plans.{$plan}.limits.api_requests_per_minute"));
        }
    }

    public function test_free_plan_can_create_and_use_a_scoped_api_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('automation.tokens.store'), [
            'name' => 'Free plan read token',
            'abilities' => ['read'],
            'expires_in_days' => 30,
        ])->assertRedirect()->assertSessionHas('plainTextToken');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Free plan read token',
        ]);

        Sanctum::actingAs($user, ['read']);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.organization.plan', 'free');
    }

    public function test_api_requests_use_the_free_plan_limit_and_return_throttle_headers(): void
    {
        config(['billing.plans.free.limits.api_requests_per_minute' => 2]);
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read']);

        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/me')->assertOk();

        $this->getJson('/api/v1/me')
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
    }

    public function test_api_requests_use_the_subscribed_plan_limit(): void
    {
        config([
            'billing.plans.free.limits.api_requests_per_minute' => 1,
            'billing.plans.pro.limits.api_requests_per_minute' => 2,
        ]);
        $user = User::factory()->create();
        $this->subscribeUserToPlan($user, 'pro');
        $this->assertSame('pro', $user->billingPlan());
        Sanctum::actingAs($user, ['read']);

        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/me')->assertStatus(429);
    }

    public function test_api_limit_is_shared_by_workspace_members_and_follows_owner_plan(): void
    {
        config(['billing.plans.pro.limits.api_requests_per_minute' => 1]);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->subscribeUserToPlan($owner, 'pro');
        $owner->currentOrganization->members()->attach($member, ['role' => 'developer']);
        $member->update(['current_organization_id' => $owner->current_organization_id]);
        $member->refresh();

        Sanctum::actingAs($owner, ['read']);
        $this->getJson('/api/v1/me')->assertOk();

        Sanctum::actingAs($member, ['read']);
        $this->getJson('/api/v1/me')->assertStatus(429);
    }

    private function subscribeUserToPlan(User $user, string $plan): void
    {
        $price = 'test-'.$plan.'-monthly';
        config(["billing.plans.{$plan}.monthly_price_id" => $price]);
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub-'.$plan,
            'stripe_status' => 'active',
            'stripe_price' => $price,
        ]);
        $subscription->items()->create([
            'stripe_id' => 'si-'.$plan,
            'stripe_product' => 'product-'.$plan,
            'stripe_price' => $price,
        ]);
    }
}
