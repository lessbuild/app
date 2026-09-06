<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_plans_without_mutation_and_creates_private_reviews_with_manage_tokens(): void
    {
        config(['billing.enforce_entitlements' => false]);
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '']);
        $input = ['document' => "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: private-command\n", 'bindings' => ['placements' => ['site' => $website->id]]];
        $url = '/api/v1/projects/'.$project->id.'/configuration';
        $this->postJson($url.'/plan', $input)->assertUnauthorized();
        Sanctum::actingAs($user, ['read']);
        $this->postJson($url.'/plan', $input)->assertForbidden();
        Sanctum::actingAs($user, ['manage']);
        $response = $this->postJson($url.'/plan', $input)->assertOk()->assertJsonPath('data.changes.0.action', 'create');
        $this->assertStringNotContainsString('private-command', $response->getContent());
        $this->assertDatabaseCount('configuration_reviews', 0);
        $this->assertDatabaseCount('environments', 0);
        $response = $this->postJson($url.'/reviews', $input)->assertCreated()->assertJsonStructure(['data' => ['id', 'plan', 'expires_at']]);
        $this->assertStringNotContainsString('private-command', $response->getContent());
        $this->assertDatabaseCount('configuration_reviews', 1);
        $this->assertDatabaseCount('environments', 0);
        $reviewId = $response->json('data.id');
        $applyUrl = $url.'/reviews/'.$reviewId.'/apply';
        $this->postJson($applyUrl, ['document' => 'replacement'])->assertUnprocessable();
        $this->assertDatabaseCount('configuration_applications', 0);
        $applied = $this->postJson($applyUrl)->assertOk()->assertJsonPath('data.status', 'locally_applied');
        $applicationId = $applied->json('data.id');
        $this->postJson($applyUrl)->assertOk()->assertJsonPath('data.id', $applicationId);
        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseCount('configuration_applications', 1);
        $this->getJson($url.'/applications/'.$applicationId)->assertOk()->assertJsonMissingPath('data.document');
        Sanctum::actingAs(User::factory()->create(), ['manage']);
        $this->postJson($applyUrl)->assertForbidden();
        $this->getJson($url.'/applications/'.$applicationId)->assertForbidden();
        $this->postJson($url.'/reviews', $input)->assertForbidden();
        $this->assertDatabaseCount('configuration_reviews', 1);
    }
}
