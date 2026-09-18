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

    public function test_environment_settings_validation_reopens_only_the_submitted_panel(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();

        $this->from(route('projects.show', $project))
            ->actingAs($developer)
            ->patch(route('environments.update', $environment), [
                '_environment_id' => $environment->id,
                '_environment_panel' => 'settings',
                'name' => '',
                'type' => 'staging',
                'branch' => 'develop',
                'is_protected' => '0',
                'requires_deployment_approval' => '0',
                'minimum_replicas' => 1,
                'maximum_replicas' => 1,
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('name');

        $response = $this->actingAs($developer)->get(route('projects.show', $project));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<details(?=[^>]*id="environment-'.$environment->id.'-settings")(?=[^>]*\bopen\b)[^>]*>/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<details(?=[^>]*id="environment-'.$environment->id.'-deployment-controls")(?=[^>]*\bopen\b)[^>]*>/',
            $content,
        );
    }

    public function test_deployment_control_validation_reopens_the_deployment_panel(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();

        $this->from(route('projects.show', $project))
            ->actingAs($developer)
            ->patch(route('environments.deployment-controls.update', $environment), [
                '_environment_id' => $environment->id,
                '_environment_panel' => 'deployment-controls',
                'deployment_locked' => '0',
                'deployment_window_enabled' => '1',
                'deployment_strategy' => 'blue_green',
                'rolling_pause_seconds' => '2',
                'automatic_rollback' => '0',
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('deployment_window_days');

        $response = $this->actingAs($developer)->get(route('projects.show', $project));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<details(?=[^>]*id="environment-'.$environment->id.'-deployment-controls")(?=[^>]*\bopen\b)[^>]*>/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<details(?=[^>]*id="environment-'.$environment->id.'-settings")(?=[^>]*\bopen\b)[^>]*>/',
            $content,
        );
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
