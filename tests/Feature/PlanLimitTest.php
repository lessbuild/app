<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.enforce_limits' => true]);
    }

    public function test_free_plan_blocks_a_second_server(): void
    {
        $user = User::factory()->create();
        $user->servers()->create(['name' => 'First']);

        $usage = app(PlanLimits::class)->usage($user, 'servers');

        $this->assertSame(1, $usage['limit']);
        $this->assertSame(1, $usage['used']);
        $this->assertFalse($usage['allowed']);

        $this->expectException(ValidationException::class);
        app(PlanLimits::class)->enforce($user, 'servers');
    }

    public function test_free_plan_blocks_a_second_website(): void
    {
        $user = User::factory()->create();
        $server = $user->servers()->create(['name' => 'Production']);
        $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'First',
            'description' => 'First website',
            'environment' => 'APP_ENV=production',
            'url' => 'first.example.com',
        ]);

        $usage = app(PlanLimits::class)->usage($user, 'websites');

        $this->assertSame(1, $usage['limit']);
        $this->assertFalse($usage['allowed']);
    }

    public function test_unlimited_plan_has_no_resource_caps(): void
    {
        config(['billing.plans.unlimited.price_id' => 'price_unlimited']);
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_unlimited',
            'stripe_status' => 'active',
            'stripe_price' => 'price_unlimited',
        ]);
        $subscription->items()->create([
            'stripe_id' => 'si_unlimited',
            'stripe_product' => 'prod_unlimited',
            'stripe_price' => 'price_unlimited',
        ]);

        $this->assertSame('unlimited', $user->billingPlan());
        $this->assertNull(app(PlanLimits::class)->usage($user, 'servers')['limit']);
        $this->assertTrue(app(PlanLimits::class)->usage($user, 'servers')['allowed']);
        $this->assertTrue(app(PlanLimits::class)->usage($user, 'websites')['allowed']);
    }

    public function test_server_creation_page_disables_creation_at_the_limit(): void
    {
        $user = User::factory()->create();
        $user->servers()->create(['name' => 'First']);

        $this->actingAs($user)->get(route('servers.create'))
            ->assertOk()
            ->assertSee('server limit has been reached')
            ->assertSee(route('billing.index'));
    }

    public function test_limit_check_and_creation_share_one_transaction(): void
    {
        $user = User::factory()->create();
        $calls = 0;

        app(PlanLimits::class)->withinLimit($user, 'servers', function ($organization) use (&$calls, $user): void {
            $calls++;
            $organization->servers()->create(['user_id' => $user->id, 'name' => 'Only server']);
        });

        $this->assertSame(1, $calls);
        $this->assertSame(1, $user->currentOrganization->servers()->count());

        try {
            app(PlanLimits::class)->withinLimit($user, 'servers', function () use (&$calls): void {
                $calls++;
            });
            $this->fail('The plan limit should have rejected the callback.');
        } catch (ValidationException) {
            $this->assertSame(1, $calls);
        }
    }
}
