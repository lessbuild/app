<?php

namespace Tests\Feature;

use App\Models\ConfigurationReview;
use App\Models\User;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationConfigurationRemovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_removal_only_web_review_warns_and_applies_with_empty_bindings(): void
    {
        [$user, $project, $environment] = $this->fixture();
        $url = route('projects.configuration.create', $project);
        $this->actingAs($user)->post($url, ['document' => "version: 2\nremove:\n  environments: [staging]\n", 'bindings' => '{}'])->assertRedirect();
        $review = ConfigurationReview::latest('id')->firstOrFail();
        $reviewUrl = route('projects.configuration.review', [$project, $review]);
        $this->get($reviewUrl)->assertOk()->assertSee('secret-version history')->assertSee('provider charges')->assertSee('staging')->assertSee('Apply reviewed configuration');
        $this->assertNotNull($environment->fresh());
        $apply = route('projects.configuration.apply', [$project, $review]);
        $this->post($apply)->assertRedirect($reviewUrl);
        $this->post($apply)->assertRedirect($reviewUrl);
        $this->get($reviewUrl)->assertOk()->assertSee('locally_applied');
        $this->assertNull($environment->fresh());
        $this->assertDatabaseCount('configuration_applications', 2);
        $this->assertDatabaseCount('websites', 1);
    }

    public function test_api_removal_requires_bindings_field_but_accepts_empty_object(): void
    {
        [$user, $project, $environment] = $this->fixture();
        $url = '/api/v1/projects/'.$project->id.'/configuration';
        $input = ['document' => "version: 2\nremove:\n  environments: [staging]\n", 'bindings' => (object) []];
        Sanctum::actingAs($user, ['manage']);
        $this->postJson($url.'/plan', ['document' => $input['document']])->assertUnprocessable()->assertJsonValidationErrors('bindings');
        $this->postJson($url.'/plan', $input)->assertOk()->assertJsonPath('data.changes.0.action', 'remove')
            ->assertJsonPath('data.changes.0.remote_data_deleted', false)->assertJsonPath('data.changes.0.remote_services_changed', false);
        $this->assertNotNull($environment->fresh());
        $reviewId = $this->postJson($url.'/reviews', $input)->assertCreated()->json('data.id');
        $apply = $url.'/reviews/'.$reviewId.'/apply';
        $id = $this->postJson($apply)->assertOk()->assertJsonPath('data.status', 'locally_applied')->json('data.id');
        $this->postJson($apply)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson($url.'/applications/'.$id)->assertOk()->assertJsonPath('data.status', 'locally_applied');
        $this->assertNull($environment->fresh());
        $reviewId = $this->postJson($url.'/reviews', $input)->assertCreated()->assertJsonPath('data.plan.changes.0.action', 'absent')->json('data.id');
        $this->postJson($url.'/reviews/'.$reviewId.'/apply')->assertOk();
        $this->assertDatabaseCount('environments', 0);
        $this->assertDatabaseCount('websites', 1);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '']);
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user,
            "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n",
            ['placements' => ['site' => $website->id]]);
        app(ApplicationConfigurationReconciler::class)->apply($review, $user);

        return [$user, $project, $project->environments()->firstOrFail()];
    }
}
