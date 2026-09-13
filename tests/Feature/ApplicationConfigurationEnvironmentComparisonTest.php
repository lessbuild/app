<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationConfigurationEnvironmentComparisonQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationConfigurationEnvironmentComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_compare_safe_recorded_metadata_without_commands_or_secret_values(): void
    {
        [$owner, $project, $from, $to] = $this->projectEnvironments();
        $from->processes()->create([
            'name' => 'queue', 'type' => 'worker', 'command' => 'private-command', 'replicas' => 1,
        ]);
        $secret = $from->variables()->create([
            'key' => 'PRIVATE_TOKEN', 'value' => 'private-secret-value', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 2, 'updated_by' => $owner->id,
        ]);
        DB::table('environment_variables')->where('id', $secret->id)->update(['value' => 'unreadable-ciphertext']);

        $comparison = app(ApplicationConfigurationEnvironmentComparisonQuery::class)->for($project, $from->id, $to->id);

        $this->assertNotNull($comparison);
        $this->assertSame('Production', $comparison->from->name);
        $this->assertSame('Staging', $comparison->to->name);
        $fields = collect($comparison->differences)->keyBy('field');
        $this->assertSame(['from' => 'main', 'to' => 'develop'], [
            'from' => $fields->get('Branch')['from'], 'to' => $fields->get('Branch')['to'],
        ]);
        $this->assertSame(['from' => 'php', 'to' => 'node'], [
            'from' => $fields->get('Runtime')['from'], 'to' => $fields->get('Runtime')['to'],
        ]);
        $serialized = json_encode($comparison, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('queue', $serialized);
        $this->assertStringNotContainsString('private-command', $serialized);
        $this->assertStringNotContainsString('private-secret-value', $serialized);
        $this->assertStringNotContainsString('unreadable-ciphertext', $serialized);

        $this->actingAs($owner)
            ->get(route('projects.configuration.compare', [
                'project' => $project,
                'from_environment_id' => $from->id,
                'to_environment_id' => $to->id,
            ]))
            ->assertOk()
            ->assertSee('Recorded environment comparison')
            ->assertSee('Branch')
            ->assertSee('develop')
            ->assertDontSee('private-command')
            ->assertDontSee('private-secret-value')
            ->assertDontSee('unreadable-ciphertext');
    }

    public function test_comparison_requires_two_environments_from_the_same_project_and_manager_access(): void
    {
        [$owner, $project, $from, $to] = $this->projectEnvironments();
        $foreign = User::factory()->create();
        $foreignProject = $foreign->currentOrganization->projects()->create([
            'name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $foreign->id,
        ]);
        $foreignEnvironment = $foreignProject->environments()->create([
            'name' => 'Foreign', 'slug' => 'foreign', 'type' => 'staging',
        ]);

        $this->assertNull(app(ApplicationConfigurationEnvironmentComparisonQuery::class)->for($project, $from->id, $foreignEnvironment->id));
        $this->actingAs($owner)
            ->get(route('projects.configuration.compare', [
                'project' => $project,
                'from_environment_id' => $from->id,
                'to_environment_id' => $foreignEnvironment->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('to_environment_id');

        $viewer = User::factory()->create();
        $project->organization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $project->organization_id]);
        $this->actingAs($viewer)
            ->get(route('projects.configuration.compare', [
                'project' => $project,
                'from_environment_id' => 'not-an-id',
                'to_environment_id' => $to->id,
            ]))
            ->assertForbidden();
    }

    /** @return array{User, Project, Environment, Environment} */
    private function projectEnvironments(): array
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Storefront', 'slug' => 'storefront', 'created_by' => $owner->id,
        ]);
        $from = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'runtime_type' => 'php', 'is_protected' => true,
        ]);
        $to = $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'develop',
            'runtime_type' => 'node',
        ]);

        return [$owner, $project, $from, $to];
    }
}
