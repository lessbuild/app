<?php

namespace Tests\Feature;

use App\Actions\Project\CreateProjectAction;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_members_with_deployment_permission_can_create_an_application(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $developer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $organization->members()->attach($developer, ['role' => 'developer']);
        $viewer->update(['current_organization_id' => $organization->id]);
        $developer->update(['current_organization_id' => $organization->id]);

        $this->actingAs($viewer)->post(route('projects.store'), [
            'name' => 'Denied application',
        ])->assertForbidden();
        $this->assertDatabaseCount('projects', 0);

        $this->actingAs($developer)->post(route('projects.store'), [
            'name' => 'Developer application',
        ])->assertRedirect();
        $this->assertDatabaseHas('projects', [
            'organization_id' => $organization->id,
            'created_by' => $developer->id,
            'name' => 'Developer application',
        ]);
    }

    public function test_omitted_preset_defaults_and_explicit_null_or_unknown_presets_fail_validation(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Default application',
        ])->assertRedirect();
        $this->assertDatabaseHas('projects', [
            'name' => 'Default application',
            'preset' => 'laravel',
        ]);

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Null preset application',
            'preset' => null,
        ])->assertSessionHasErrors('preset');
        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Unknown preset application',
            'preset' => 'not-configured',
        ])->assertSessionHasErrors('preset');
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_curated_template_version_is_recorded_without_versioning_unpublished_presets(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Curated application', 'preset' => 'laravel',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Unpublished application', 'preset' => 'nextjs',
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'name' => 'Curated application',
            'preset' => 'laravel',
            'template_version' => '1.0.0',
        ]);
        $this->assertDatabaseHas('projects', [
            'name' => 'Unpublished application',
            'preset' => 'nextjs',
            'template_version' => null,
        ]);
    }

    public function test_application_creation_displays_non_secret_curated_template_guidance(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertSee('Curated template 1.0.0')
            ->assertSee('2 managed resources')
            ->assertSee('5 readiness checks')
            ->assertDontSee('DB_PASSWORD')
            ->assertDontSee('REDIS_PASSWORD');
    }

    public function test_application_creation_rolls_back_when_process_configuration_fails(): void
    {
        $owner = User::factory()->create();
        $entitlements = Mockery::mock(Entitlements::class);
        $entitlements->shouldReceive('allows')
            ->once()
            ->with($owner->currentOrganization, 'workers')
            ->andThrow(new RuntimeException('entitlement check failed'));
        $this->app->instance(Entitlements::class, $entitlements);

        try {
            app(CreateProjectAction::class)->handle($owner->currentOrganization, $owner, [
                'name' => 'Rolled back application',
                'description' => null,
                'preset' => 'laravel',
            ]);
            $this->fail('Expected project creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('entitlement check failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('environments', 0);
        $this->assertDatabaseCount('environment_processes', 0);
    }
}
