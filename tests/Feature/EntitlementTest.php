<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => true]);
    }

    public function test_free_plan_keeps_core_deployments_but_blocks_paid_capabilities(): void
    {
        $user = User::factory()->create();
        $entitlements = app(Entitlements::class);

        $this->assertTrue($entitlements->allows($user, 'deployments'));
        foreach ([
            'previews', 'api', 'scheduled_deployments', 'resources', 'status_pages',
            'cost_controls', 'teams', 'alerts', 'audit', 'high_availability', 'sso',
        ] as $feature) {
            $this->assertFalse($entitlements->allows($user, $feature), $feature.' should be paid.');
        }
        $this->expectException(ValidationException::class);
        $entitlements->enforce($user, 'backups');
    }

    public function test_free_workspace_cannot_bypass_team_or_backup_gates_over_http(): void
    {
        $user = User::factory()->create();
        config(['billing.plans.free.limits.members' => null]);

        $this->actingAs($user)->post(route('organizations.invitations.store'), [
            'email' => 'member@example.com', 'role' => 'developer',
        ])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('organization_invitations', 0);

        $this->actingAs($user)->post(route('backups.destinations.store'), [
            'name' => 'Remote', 'endpoint' => 'https://s3.example.com', 'bucket' => 'buildpusher-backups',
            'region' => 'us-east-1', 'access_key' => 'key', 'secret_key' => 'secret', 'path_prefix' => 'workspace',
        ])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('backup_destinations', 0);
    }

    public function test_unlimited_plan_grants_every_entitlement(): void
    {
        config(['billing.plans.unlimited.monthly_price_id' => 'price_unlimited']);
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_unlimited', 'stripe_status' => 'active', 'stripe_price' => 'price_unlimited']);
        $subscription->items()->create(['stripe_id' => 'si_unlimited', 'stripe_product' => 'prod_unlimited', 'stripe_price' => 'price_unlimited']);

        $this->assertTrue(app(Entitlements::class)->allows($user, 'anything_future'));
    }

    public function test_free_application_presets_do_not_create_paid_worker_services(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Free application',
            'preset' => 'laravel',
        ])->assertRedirect();

        $this->assertDatabaseCount('environment_processes', 0);
    }

    public function test_free_workspace_cannot_enable_automatic_provider_monitoring(): void
    {
        $user = User::factory()->create();
        $payload = [
            'name' => 'Cloud', 'description' => 'Cloud account', 'provider' => 'digitalocean',
            'token' => 'secret', 'connection_monitoring_enabled' => '1',
            'connection_check_interval_minutes' => 60, 'connection_failure_threshold' => 2,
        ];

        $this->actingAs($user)->post(route('providers.store'), $payload)
            ->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('providers', 0);

        $payload['connection_monitoring_enabled'] = '0';
        $this->actingAs($user)->post(route('providers.store'), $payload)->assertRedirect();
        $this->assertFalse($user->providers()->sole()->connection_monitoring_enabled);
    }

    public function test_free_workspace_cannot_export_the_paid_audit_log(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('activity.index'))
            ->assertOk()
            ->assertSee('Unlock CSV export')
            ->assertDontSee('Export CSV');
        $this->actingAs($user)->get(route('activity.export'))->assertSessionHasErrors('plan');
    }

    public function test_free_workspace_cannot_enable_scaling_or_hibernation_on_an_environment(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id, 'name' => 'Runtime', 'slug' => 'runtime', 'preset' => 'custom',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
        ]);
        $base = [
            'name' => 'Production', 'type' => 'production', 'branch' => 'main',
            'is_protected' => '1', 'requires_deployment_approval' => '1',
            'minimum_replicas' => 1,
        ];

        $this->actingAs($user)->patch(route('environments.update', $environment), [
            ...$base, 'maximum_replicas' => 2,
        ])->assertSessionHasErrors('plan');
        $this->assertSame(1, $environment->fresh()->maximum_replicas);

        $this->actingAs($user)->patch(route('environments.update', $environment), [
            ...$base, 'maximum_replicas' => 1, 'hibernate_after_minutes' => 15,
        ])->assertSessionHasErrors('plan');
        $this->assertNull($environment->fresh()->hibernate_after_minutes);
    }
}
