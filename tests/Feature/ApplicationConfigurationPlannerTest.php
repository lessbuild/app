<?php

namespace Tests\Feature;

use App\Models\ConfigurationOwnership;
use App\Models\User;
use App\Services\ApplicationConfigurationPlanner;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationConfigurationPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_previews_creation_and_adoption_without_mutation_or_command_disclosure(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        $server = $owner->servers()->create(['name' => 'Test']);
        $website = $owner->websites()->create(['server_id' => $server->id, 'name' => 'Test', 'url' => 'test.example', 'description' => 'Test', 'environment' => '']);
        $yaml = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: private-command-content\n";
        $bindings = ['placements' => ['site' => $website->id]];
        $planner = app(ApplicationConfigurationPlanner::class);
        config(['billing.enforce_entitlements' => true, 'billing.plans.free.entitlements' => []]);
        try {
            $planner->plan($project, $owner, $yaml."    resources:\n      cache:\n        type: redis\n        managed: true\n", $bindings);
            $this->fail('Unentitled resource accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $plan = $planner->plan($project, $owner, $yaml, $bindings);
        $this->assertSame('create', $plan['changes'][0]['action']);
        $this->assertTrue($plan['apply_available']);
        $this->assertStringNotContainsString('private-command-content', json_encode($plan));
        $this->assertDatabaseCount('environments', 0);
        $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging']);
        $this->assertSame('adoption_required', $planner->plan($project, $owner, $yaml, $bindings)['changes'][0]['action']);
        $adoptionYaml = str_replace('    type: staging', "    adopt: true\n    type: staging", $yaml);
        $adoptionPlan = $planner->plan($project, $owner, $adoptionYaml, $bindings);
        $this->assertSame('adopt', $adoptionPlan['changes'][0]['action']);
        $this->assertNotSame($planner->plan($project, $owner, $yaml, $bindings)['fingerprint'], $adoptionPlan['fingerprint']);
        $this->assertDatabaseCount('configuration_ownerships', 0);
        try {
            $planner->plan($project, $owner, str_replace('adopt: true', 'adopt: "true"', $adoptionYaml), $bindings);
            $this->fail('String adoption consent accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        $this->assertDatabaseCount('environments', 1);
        Queue::assertNothingPushed();
        $review = app(ApplicationConfigurationReviews::class)->create($project, $owner, $yaml, $bindings);
        $this->assertSame($yaml, $review->fresh()->document);
        $this->assertStringNotContainsString('private-command-content', $review->getRawOriginal('document'));
        $this->assertArrayNotHasKey('document', $review->toArray());
        $this->assertArrayNotHasKey('bindings', $review->toArray());
        $this->assertTrue($review->expires_at->isFuture());
        $this->assertSame($owner->id, $review->requested_by);
        $reviews = app(ApplicationConfigurationReviews::class);
        $this->assertSame($review->summary, $reviews->inspect($review, $owner));
        $ownership = ConfigurationOwnership::create([
            'project_id' => $project->id, 'environment_slug' => 'staging', 'kind' => 'environment',
            'logical_name' => 'staging', 'resource_id' => $project->environments()->first()->id,
        ]);
        $this->assertSame('update', $planner->plan($project, $owner, $yaml, $bindings)['changes'][0]['action']);
        try {
            $reviews->inspect($review, $owner);
            $this->fail('Ownership change did not invalidate review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $ownership->update(['logical_name' => 'different']);
        try {
            $planner->plan($project, $owner, $yaml, $bindings);
            $this->fail('Conflicting ownership accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $ownership->update(['logical_name' => 'staging', 'resource_id' => 999999]);
        try {
            $planner->plan($project, $owner, $yaml, $bindings);
            $this->fail('Dangling ownership accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $ownership->delete();
        $project->environments()->first()->update(['build_command' => 'changed-after-review']);
        try {
            $reviews->inspect($review, $owner);
            $this->fail('Stale review accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['review' => ['Configuration changed after this review. Create a new review.']], $exception->errors());
        }
        $review->update(['expires_at' => now()->subSecond()]);
        $this->expectException(ValidationException::class);
        $reviews->inspect($review, $owner);
    }
}
