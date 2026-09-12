<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_environment_creation_keeps_unique_slugs_and_production_guard(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)->post(route('environments.store', $project), $this->payload('Quality Assurance'))
            ->assertRedirect();
        $this->assertDatabaseHas('environments', ['project_id' => $project->id, 'slug' => 'quality-assurance']);

        $production = $this->payload('Production');
        $production['type'] = 'production';
        $this->actingAs($user)->post(route('environments.store', $project), $production)->assertRedirect();
        $this->actingAs($user)->post(route('environments.store', $project), $production)
            ->assertSessionHasErrors('type');
        $this->assertSame(1, $project->environments()->where('type', 'production')->count());
    }

    public function test_protected_environment_creation_requires_management_without_writing(): void
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($developer, ['role' => 'developer']);
        $developer->update(['current_organization_id' => $organization->id]);
        $project = $this->project($owner);
        $payload = $this->payload('Protected');
        $payload['is_protected'] = '1';

        $this->actingAs($developer)->post(route('environments.store', $project), $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_environment_update_and_control_actions_keep_existing_persistence(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $environment = $project->environments()->create([
            ...$this->payload('Staging'),
            'slug' => 'staging',
        ]);

        $this->actingAs($user)->patch(route('environments.update', $environment), [
            ...$this->payload('Updated'),
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
        ])->assertSessionHas('success', 'Environment updated.');
        $this->assertSame('Updated', $environment->fresh()->name);

        $this->actingAs($user)->patch(route('environments.deployment-controls.update', $environment), [
            'deployment_locked' => '1',
            'deployment_lock_reason' => 'Release freeze',
            'deployment_window_enabled' => '0',
        ])->assertSessionHas('success', 'Deployment controls updated.');
        $this->assertSame($user->id, $environment->fresh()->deployment_locked_by);
    }

    public function test_production_cannot_be_deleted_but_a_scoped_child_and_non_production_can(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $production = $project->environments()->create([
            ...$this->payload('Production'),
            'slug' => 'production',
            'type' => 'production',
        ]);
        $staging = $project->environments()->create([
            ...$this->payload('Staging'),
            'slug' => 'staging',
        ]);
        $variable = $production->variables()->create([
            'key' => 'TOKEN',
            'value' => 'secret',
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->delete(route('environments.destroy', $production))
            ->assertStatus(422);
        $this->assertModelExists($production->fresh());

        $this->actingAs($user)->delete(route('environments.variables.destroy', [$staging, $variable]))
            ->assertNotFound();
        $this->assertModelExists($variable->fresh());

        $this->actingAs($user)->delete(route('environments.destroy', $staging))
            ->assertSessionHas('success', 'Environment deleted.');
        $this->assertModelMissing($staging);
    }

    private function project(User $user): Project
    {
        return $user->currentOrganization->projects()->create([
            'created_by' => $user->id,
            'name' => 'Application',
            'slug' => 'application',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $name): array
    {
        return [
            'name' => $name,
            'type' => 'staging',
            'branch' => 'main',
            'is_protected' => '0',
            'requires_deployment_approval' => '0',
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'hibernate_after_minutes' => null,
        ];
    }
}
