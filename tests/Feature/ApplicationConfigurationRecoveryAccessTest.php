<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationConfigurationRecoveryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_find_and_cancel_a_revoked_requesters_pending_operation(): void
    {
        [$owner, $requester, $project, $review, $application, $operation] = $this->fixture();
        $owner->currentOrganization->members()->detach($requester->id);
        $reviewUrl = route('projects.configuration.review', [$project, $review]);
        $this->actingAs($owner)->get(route('projects.configuration.create', $project))->assertOk()
            ->assertSee('Recent application receipts')->assertSee($reviewUrl, false)->assertDontSee('private-command');
        $this->get($reviewUrl)->assertOk()->assertSee('Cancel pending deployment')->assertDontSee('private-command');
        // Management can stop pending work but cannot apply another requester's review.
        $this->post(route('projects.configuration.apply', [$project, $review]))->assertForbidden();
        $cancel = route('projects.configuration.cancel', [$project, $review, $operation]);
        $this->post($cancel)->assertRedirect($reviewUrl);
        $this->post($cancel)->assertRedirect($reviewUrl);
        $this->get($reviewUrl)->assertOk()->assertSee('canceled')->assertDontSee('Retry failed deployment');
        $this->assertSame('canceled', $operation->fresh()->status);
        $this->assertDatabaseCount('builds', 0);
        $this->assertDatabaseCount('environments', 1);
        $this->actingAs($requester)->post($cancel)->assertForbidden();
        $other = User::factory()->create();
        $this->actingAs($other)->get($reviewUrl)->assertForbidden();
        $this->get(route('projects.configuration.create', $project))->assertForbidden();
    }

    public function test_api_management_can_cancel_another_requesters_pending_intent_but_read_tokens_cannot(): void
    {
        [$owner, $requester, $project, $review, $application, $operation] = $this->fixture();
        $url = '/api/v1/projects/'.$project->id.'/configuration/applications/'.$application->id.'/operations/'.$operation->id.'/cancel';
        Sanctum::actingAs($owner, ['read']);
        $this->postJson($url)->assertForbidden();
        Sanctum::actingAs($owner, ['manage']);
        $this->postJson($url)->assertOk()->assertJsonPath('data.operations.0.status', 'canceled');
        $this->assertDatabaseCount('builds', 0);
        Sanctum::actingAs(User::factory()->create(), ['manage']);
        $this->postJson($url)->assertForbidden();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($requester->id, ['role' => 'admin']);
        $requester->update(['current_organization_id' => $organization->id]);
        $project = $organization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        $provider = $owner->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'private', 'description' => 'Test']);
        $server = $owner->servers()->create(['name' => 'Server', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $repository = $owner->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $review = app(ApplicationConfigurationReviews::class)->create($project, $requester,
            "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: private-command\n    deploy:\n      repository: app\n",
            ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]]);
        $application = app(ApplicationConfigurationReconciler::class)->apply($review, $requester);

        return [$owner, $requester, $project, $review, $application, $application->operations()->firstOrFail()];
    }
}
