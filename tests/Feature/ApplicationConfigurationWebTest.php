<?php

namespace Tests\Feature;

use App\Models\ConfigurationReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationConfigurationWebTest extends TestCase
{
    use RefreshDatabase;

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
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Version 2 YAML')->assertSee('CATALOG_TOKEN')
            ->assertDontSee('catalog-private-value')->assertDontSee('invalid-ciphertext')->assertDontSee('foreign.test');
        $this->from($url)->post($url, ['document' => 'private-command', 'bindings' => '{}'])->assertSessionHasErrors('document')->assertSessionMissing('_old_input');
        $this->post($url, ['document' => "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: private-command\n", 'bindings' => json_encode(['placements' => ['site' => $website->id]])])->assertRedirect();
        $review = ConfigurationReview::firstOrFail();
        $reviewUrl = route('projects.configuration.review', [$project, $review]);
        $this->get($reviewUrl)->assertOk()->assertSee('Apply reviewed configuration')->assertSee('Reviewed fields')->assertSee('build_command')->assertDontSee('private-command');
        $review->update(['expires_at' => now()->subMinute()]);
        $this->get($reviewUrl)->assertUnprocessable()->assertSee('This review cannot be applied')->assertDontSee('Apply reviewed configuration');
        $this->from($reviewUrl)->post(route('projects.configuration.apply', [$project, $review]))
            ->assertRedirect($url)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('configuration_applications', 0);
        $review->update(['expires_at' => now()->addMinutes(15)]);
        $this->post(route('projects.configuration.apply', [$project, $review]))->assertRedirect($reviewUrl);
        $this->get($reviewUrl)->assertOk()->assertSee('locally_applied')->assertDontSee('private-command');
        $this->assertDatabaseCount('environments', 2);
    }
}
