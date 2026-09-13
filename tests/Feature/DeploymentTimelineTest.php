<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationReview;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_page_shows_a_timeline_and_exact_deployment_context_without_configuration_payload(): void
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Application server',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Application',
            'slug' => 'application',
            'created_by' => $owner->id,
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'requires_deployment_approval' => true,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
        $requestedAt = CarbonImmutable::parse('2026-09-13 08:00:00 UTC');
        $approvedAt = CarbonImmutable::parse('2026-09-13 08:01:00 UTC');
        $activatedAt = CarbonImmutable::parse('2026-09-13 08:04:00 UTC');
        $finishedAt = CarbonImmutable::parse('2026-09-13 08:06:00 UTC');
        $revision = str_repeat('b', 40);
        $build = $repository->builds()->create([
            'environment_id' => $environment->id,
            'requested_by' => $owner->id,
            'approved_by' => $owner->id,
            'approved_at' => $approvedAt,
            'status' => Build::STATUS_SUCCEEDED,
            'setup_stage' => 15,
            'revision' => $revision,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => $requestedAt,
            'started_at' => $requestedAt,
            'activated_at' => $activatedAt,
            'finished_at' => $finishedAt,
        ]);
        $review = ConfigurationReview::create([
            'project_id' => $project->id,
            'requested_by' => $owner->id,
            'document' => 'private-configuration-payload',
            'bindings' => [],
            'summary' => [],
            'expires_at' => now()->addHour(),
        ]);
        $application = ConfigurationApplication::create([
            'configuration_review_id' => $review->id,
            'status' => 'succeeded',
        ]);
        $operation = $application->operations()->create([
            'environment_slug' => 'production',
            'environment_id' => $environment->id,
            'build_id' => $build->id,
            'kind' => 'deploy',
            'status' => 'succeeded',
            'payload' => ['command' => 'private-configuration-payload'],
            'intent_digest' => str_repeat('c', 64),
            'started_at' => $requestedAt,
            'completed_at' => $finishedAt,
        ]);

        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('Deployment evidence')
            ->assertSee('Deployment timeline')
            ->assertSee('Prepare deployment')
            ->assertSee('Build application')
            ->assertSee('Verify deployment health')
            ->assertSee($revision)
            ->assertSee($owner->name)
            ->assertSee("Review #{$review->id}")
            ->assertSee("Application #{$application->id}")
            ->assertSee("Operation #{$operation->id}")
            ->assertSee(str_repeat('c', 64))
            ->assertDontSee('private-configuration-payload');
    }
}
