<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\ConfigurationReview;
use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationConfigurationWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_request_validation_does_not_flash_documents_or_bindings(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $url = route('projects.configuration.create', $project);

        $this->actingAs($user)->from($url)->post($url, ['bindings' => '{}'])
            ->assertSessionHasErrors('document')
            ->assertSessionMissing('_old_input');
        $this->actingAs($user)->from($url)->post($url, [
            'document' => "version: 2\nremove:\n  environments: [staging]\n",
            'bindings' => '{invalid-json',
        ])->assertSessionHasErrors('bindings')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('configuration_reviews', 0);
    }

    public function test_non_manager_is_denied_before_configuration_validation(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $organization->id]);
        $project = $organization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        $url = route('projects.configuration.create', $project);

        $this->actingAs($viewer)->post($url, [])->assertForbidden();
        $this->assertDatabaseCount('configuration_reviews', 0);
    }

    public function test_manager_can_open_configuration_in_application_context_and_submit_a_review(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test']);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'App',
            'url' => 'app.test',
            'description' => 'Test',
            'environment' => '',
        ]);
        $applicationUrl = route('projects.show', ['project' => $project, 'dialog' => 'application-configuration']);
        $storeUrl = route('projects.configuration.store', [
            'project' => $project,
            'dialog' => 'application-configuration',
        ]);

        $this->actingAs($user)->get($applicationUrl)
            ->assertOk()
            ->assertSee('application-configuration-dialog', false)
            ->assertSee('data-modal-content-url', false)
            ->assertSee('Loading configuration workflow');

        $fragment = $this->actingAs($user)->get(route('projects.configuration.dialog', $project));
        $fragment->assertOk()
            ->assertSee('Create a review')
            ->assertSee('Version 2 YAML document')
            ->assertDontSee('<html', false)
            ->assertDontSee('Setup Information');

        $this->from($storeUrl)->post($storeUrl, ['bindings' => '{"submitted":"private-binding"}'])
            ->assertRedirect($applicationUrl)
            ->assertSessionHasErrors('document')
            ->assertSessionMissing('_old_input');
        $this->get(route('projects.configuration.dialog', $project))
            ->assertSee('required')
            ->assertDontSee('private-binding');

        $response = $this->post($storeUrl, [
            'document' => "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n",
            'bindings' => json_encode(['placements' => ['site' => $website->id]]),
        ]);
        $review = ConfigurationReview::query()->sole();

        $response->assertRedirect(route('projects.show', [
            'project' => $project,
            'dialog' => 'application-configuration',
            'configuration_review' => $review->id,
        ]));

        $this->actingAs($user)->get(route('projects.configuration.dialog', [
            'project' => $project,
            'configuration_review' => $review->id,
        ]))->assertOk()->assertSee('Apply reviewed configuration')->assertDontSee('<html', false);

        $review->update(['expires_at' => now()->subMinute()]);
        $this->get(route('projects.configuration.dialog', [
            'project' => $project,
            'configuration_review' => $review->id,
        ]))->assertOk()->assertSee('This review cannot be applied')->assertDontSee('Apply reviewed configuration');
    }

    public function test_configuration_fragment_keeps_workspace_authorization(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $organization->id]);
        $project = $organization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);

        $this->actingAs($viewer)->get(route('projects.configuration.dialog', $project))->assertForbidden();
    }

    public function test_browser_can_review_and_apply_without_echoing_commands(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '']);
        $url = route('projects.configuration.create', $project);
        $sourceEnvironment = $project->environments()->create(['name' => 'Secrets', 'slug' => 'secrets', 'type' => 'staging']);
        $secret = $sourceEnvironment->variables()->create(['key' => 'CATALOG_TOKEN', 'value' => 'catalog-private-value', 'is_secret' => true, 'scope' => 'all', 'current_version' => 1, 'updated_by' => $user->id]);
        DB::table('environment_variables')->where('id', $secret->id)->update(['value' => 'invalid-ciphertext']);
        $other = User::factory()->create();
        $otherServer = $other->servers()->create(['name' => 'Foreign']);
        $other->websites()->create(['server_id' => $otherServer->id, 'name' => 'Foreign website', 'url' => 'foreign.test', 'description' => 'Test', 'environment' => '']);
        $this->actingAs($user)->get($url)->assertOk()->assertSee('<details id="configuration-insights"', false)->assertSee('Current environment overview')->assertSee('data-configuration-environment', false)->assertDontSee('<table', false)->assertSee('Version 2 authoring guide')->assertSee('Version 2 YAML')->assertSee('CATALOG_TOKEN')
            ->assertDontSee('catalog-private-value')->assertDontSee('invalid-ciphertext')->assertDontSee('foreign.test');
        $this->from($url)->post($url, ['document' => 'private-command', 'bindings' => '{}'])->assertSessionHasErrors('document')->assertSessionMissing('_old_input');
        $this->post($url, ['document' => "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: private-command\n", 'bindings' => json_encode(['placements' => ['site' => $website->id]])])->assertRedirect();
        $review = ConfigurationReview::firstOrFail();
        $reviewUrl = route('projects.configuration.review', [$project, $review]);
        $this->get($reviewUrl)->assertOk()->assertSee('Apply reviewed configuration')->assertSee('Reviewed fields')->assertSee('data-configuration-change', false)->assertDontSee('<table', false)->assertSee('build_command')->assertDontSee('private-command');
        $review->update(['expires_at' => now()->subMinute()]);
        $this->get($reviewUrl)->assertUnprocessable()->assertSee('This review cannot be applied')->assertDontSee('Apply reviewed configuration');
        $this->from($reviewUrl)->post(route('projects.configuration.apply', [$project, $review]))
            ->assertRedirect($url)->assertSessionHasErrors('review')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('configuration_applications', 0);
        $review->update(['expires_at' => now()->addMinutes(15)]);
        $this->post(route('projects.configuration.apply', [$project, $review]))->assertRedirect($reviewUrl);
        $this->get($reviewUrl)->assertOk()->assertSee('locally_applied')->assertDontSee('private-command');
        $this->assertDatabaseCount('environments', 2);
    }
}
