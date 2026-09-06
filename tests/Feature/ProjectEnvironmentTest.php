<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_starts_with_protected_production_environment(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Storefront',
            'description' => 'Customer storefront',
        ]);

        $project = Project::query()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($owner->current_organization_id, $project->organization_id);
        $environment = $project->environments()->sole();
        $this->assertSame('production', $environment->type);
        $this->assertTrue($environment->is_protected);
        $this->assertTrue($environment->requires_deployment_approval);
    }

    public function test_developer_can_create_staging_but_cannot_change_production(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();

        $this->actingAs($developer)->post(route('environments.store', $project), $this->environmentPayload())
            ->assertRedirect();
        $this->assertDatabaseHas('environments', ['project_id' => $project->id, 'type' => 'staging']);

        $production = $project->environments()->where('type', 'production')->sole();
        $this->actingAs($developer)->patch(route('environments.update', $production), $this->environmentPayload('Production'))
            ->assertForbidden();
    }

    public function test_environment_cannot_attach_another_workspace_resource(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $outsider = User::factory()->create();
        $server = $outsider->servers()->create(['name' => 'private-server']);

        $this->actingAs($developer)->post(route('environments.store', $project), [
            ...$this->environmentPayload(),
            'server_id' => $server->id,
        ])->assertSessionHasErrors('server_id');
    }

    public function test_environment_variable_is_encrypted_and_never_rendered(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();

        $this->actingAs($developer)->post(route('environments.variables.store', $environment), [
            'key' => 'API_SECRET',
            'value' => 'super-secret-value',
            'is_secret' => '1',
        ])->assertRedirect();

        $stored = DB::table('environment_variables')->value('value');
        $this->assertNotSame('super-secret-value', $stored);
        $this->actingAs($developer)->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('super-secret-value');
    }

    private function workspaceProject(): array
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($developer, ['role' => 'developer']);
        $developer->update(['current_organization_id' => $organization->id]);
        $project = $organization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Storefront',
            'slug' => 'storefront',
        ]);
        $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'is_protected' => true, 'requires_deployment_approval' => true,
        ]);
        $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'develop',
        ]);

        return [$owner, $developer, $project];
    }

    private function environmentPayload(string $name = 'QA'): array
    {
        return [
            'name' => $name,
            'type' => 'staging',
            'branch' => 'develop',
            'is_protected' => '0',
            'requires_deployment_approval' => '0',
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'hibernate_after_minutes' => null,
        ];
    }
}
