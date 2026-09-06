<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationConfigurationManagedPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_database_credential_or_server_address_changes_require_a_new_review(): void
    {
        [$user, $project, $server, $website] = $this->fixture();
        $yaml = $this->document('mysql', 'database');
        $bindings = ['placements' => ['site' => $website->id]];
        $reviews = app(ApplicationConfigurationReviews::class);
        foreach (['password', 'address'] as $change) {
            $review = $reviews->create($project, $user, $yaml, $bindings);
            if ($change === 'password') {
                $website->update(['database_password' => 'rotated-private-password']);
            } else {
                $server->update(['public_ip' => '192.0.2.2']);
            }
            try {
                app(ApplicationConfigurationReconciler::class)->apply($review, $user);
                $this->fail('Changed managed credentials passed the saved review.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('review', $exception->errors());
            }
            $this->assertDatabaseCount('configuration_applications', 0);
            $this->assertDatabaseCount('environment_resources', 0);
            $this->assertStringNotContainsString('private-password', json_encode($review->summary));
        }
        $receipt = app(ApplicationConfigurationReconciler::class)->apply($reviews->create($project, $user, $yaml, $bindings), $user);
        $resource = $project->environments()->firstOrFail()->resources()->firstOrFail();
        $this->assertSame('locally_applied', $receipt->status);
        $this->assertSame('rotated-private-password', $resource->configuration['variables']['DB_PASSWORD']);
        $this->assertSame('192.0.2.2', $resource->configuration['variables']['DB_HOST']);
        $this->assertStringNotContainsString('private-password', $resource->getRawOriginal('configuration'));
    }

    public function test_omitted_managed_valkey_resource_cannot_be_given_a_conflicting_second_container(): void
    {
        [$user, $project, $server, $website] = $this->fixture();
        $bindings = ['placements' => ['site' => $website->id]];
        $reviews = app(ApplicationConfigurationReviews::class);
        app(ApplicationConfigurationReconciler::class)->apply($reviews->create($project, $user, $this->document('valkey', 'cache'), $bindings), $user);
        try {
            $reviews->create($project, $user, $this->document('valkey', 'queue'), $bindings);
            $this->fail('Two managed containers were assigned the same environment port.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $this->assertDatabaseCount('environment_resources', 1);
    }

    private function document(string $type, string $name): string
    {
        return "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n    resources:\n      {$name}:\n        type: {$type}\n        managed: true\n";
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Server', 'public_ip' => '192.0.2.1']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '', 'database_password' => 'initial-private-password']);

        return [$user, $project, $server, $website];
    }
}
