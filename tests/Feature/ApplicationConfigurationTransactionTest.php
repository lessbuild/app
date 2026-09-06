<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use App\Services\ApplicationConfigurationTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApplicationConfigurationTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_local_topology_reapplies_without_duplicate_objects_or_secret_versions(): void
    {
        config(['billing.enforce_entitlements' => false]);
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'Test', 'url' => 'test.example', 'description' => 'Test', 'environment' => '']);
        $sourceEnvironment = $project->environments()->create(['name' => 'Secrets', 'slug' => 'secrets', 'type' => 'staging']);
        $source = $sourceEnvironment->variables()->create(['key' => 'TOKEN', 'value' => 'private-value', 'is_secret' => true, 'scope' => 'all', 'current_version' => 1, 'updated_by' => $user->id]);
        $yaml = <<<'YAML'
version: 2
environments:
  staging:
    type: staging
    placement: site
    runtime:
      type: node
      start_command: npm start
      port: 3000
    processes:
      worker:
        type: worker
        command: npm run worker
        replicas: 2
    resources:
      cache:
        type: redis
        managed: true
    variables:
      API_TOKEN:
        secret_ref: token
        scope: runtime
YAML;
        $bindings = ['placements' => ['site' => $website->id], 'secrets' => ['token' => $source->id]];
        $reviews = app(ApplicationConfigurationReviews::class);
        $reconciler = app(ApplicationConfigurationReconciler::class);
        $admin = User::factory()->create();
        $user->currentOrganization->members()->attach($admin->id, ['role' => 'admin']);
        $admin->update(['current_organization_id' => $project->organization_id]);
        $revokedReview = $reviews->create($project, $admin, $yaml, $bindings);
        $user->currentOrganization->members()->detach($admin->id);
        try {
            $reconciler->apply($revokedReview, $admin);
            $this->fail('Revoked administrator applied a review.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('configuration_applications', 0);
            $this->assertDatabaseCount('configuration_ownerships', 0);
            $this->assertNull($revokedReview->fresh()->applied_at);
        }
        $first = $reconciler->apply($reviews->create($project, $user, $yaml, $bindings), $user);
        $environment = $project->environments()->where('slug', 'staging')->firstOrFail();
        $this->assertSame('node', $environment->runtime_type);
        $this->assertSame(3000, $environment->container_port);
        $this->assertSame('npm run worker', $environment->processes()->first()->command);
        $this->assertSame(2, $environment->processes()->first()->replicas);
        $this->assertSame(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'], $environment->resources()->first()->configuration['variables']);
        $variable = $environment->variables()->firstOrFail();
        $this->assertSame('private-value', $variable->value);
        $ciphertext = $variable->getRawOriginal('value');
        $sourceProject = $user->currentOrganization->projects()->create(['name' => 'Sources', 'slug' => 'sources', 'created_by' => $user->id]);
        $sourceEnvironment->update(['project_id' => $sourceProject->id]);
        $staleReview = $reviews->create($project, $user, $yaml, $bindings);
        $source->update(['value' => 'changed-without-version-increment']);
        try {
            $reconciler->apply($staleReview, $user);
            $this->fail('Changed external secret source was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertSame($ciphertext, $variable->fresh()->getRawOriginal('value'));
        $source->update(['value' => 'private-value']);
        foreach ([str_replace('type: redis', 'type: postgresql', $yaml), str_replace('managed: true', 'managed: false', $yaml)] as $replacementYaml) {
            try {
                $reviews->create($project, $user, $replacementYaml, $bindings);
                $this->fail('Implicit datastore replacement accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('plan', $exception->errors());
            }
        }
        $this->assertSame('redis', $environment->resources()->firstOrFail()->type);
        $this->assertTrue($environment->resources()->firstOrFail()->is_managed);
        $secondReview = $reviews->create($project, $user, $yaml, $bindings);
        $this->assertSame(['update'], array_values(array_unique(array_column($secondReview->summary['changes'], 'action'))));
        $second = $reconciler->apply($secondReview, $user);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('environments', 2);
        $this->assertDatabaseCount('environment_processes', 1);
        $this->assertDatabaseCount('environment_resources', 1);
        $this->assertDatabaseCount('environment_variables', 2);
        $this->assertDatabaseCount('configuration_ownerships', 4);
        $this->assertSame(1, $variable->versions()->count());
        $this->assertSame($ciphertext, $variable->fresh()->getRawOriginal('value'));
        $removalDocument = Yaml::parse($yaml);
        unset($removalDocument['environments']['staging']['resources'], $removalDocument['environments']['staging']['processes'], $removalDocument['environments']['staging']['variables']);
        $removalDocument['environments']['staging']['remove'] = ['resources' => ['cache']];
        $removalYaml = Yaml::dump($removalDocument, 10);
        $removalReview = $reviews->create($project, $user, $removalYaml, $bindings);
        $this->assertSame('detach', $removalReview->summary['changes'][1]['action']);
        $this->assertFalse($removalReview->summary['changes'][1]['remote_data_deleted']);
        $reconciler->apply($removalReview, $user);
        $this->assertDatabaseCount('environment_resources', 0);
        $this->assertDatabaseCount('environment_processes', 1);
        $this->assertDatabaseCount('environment_variables', 2);
        $this->assertDatabaseCount('configuration_ownerships', 3);
        $repeatRemoval = $reviews->create($project, $user, $removalYaml, $bindings);
        $this->assertSame('absent', $repeatRemoval->summary['changes'][1]['action']);
        $reconciler->apply($repeatRemoval, $user);
        $this->assertDatabaseCount('environment_processes', 1);
        $manual = $environment->resources()->create(['name' => 'manual', 'type' => 'redis', 'is_managed' => false]);
        $removalDocument['environments']['staging']['remove']['resources'] = ['manual'];
        try {
            $reviews->create($project, $user, Yaml::dump($removalDocument, 10), $bindings);
            $this->fail('Manual resource removal was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $this->assertNotNull($manual->fresh());
        $this->assertDatabaseCount('configuration_applications', 4);
        $sourceEnvironment->update(['project_id' => $project->id, 'type' => 'production']);
        try {
            $reviews->create($project, $user, str_replace('type: staging', 'type: production', $yaml), $bindings);
            $this->fail('A second production environment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $this->assertSame('staging', $environment->fresh()->type);
    }

    public function test_local_writes_roll_back_and_successful_retries_return_the_same_receipt(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'Test', 'url' => 'test.example', 'description' => 'Test', 'environment' => '']);
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user,
            "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n",
            ['placements' => ['site' => $website->id]]);
        $transactions = app(ApplicationConfigurationTransaction::class);
        try {
            $transactions->run($review, $user, function () use ($project) {
                $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging']);
                throw new RuntimeException('Injected local failure');
            });
            $this->fail('Failure swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected local failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('environments', 0);
        $this->assertDatabaseCount('configuration_applications', 0);
        $this->assertNull($review->fresh()->applied_at);
        $receipt = app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        $environment = $project->environments()->firstOrFail();
        $this->assertSame($website->id, $environment->website_id);
        $this->assertSame('php', $environment->runtime_type);
        $this->assertDatabaseHas('configuration_ownerships', ['project_id' => $project->id, 'kind' => 'environment', 'resource_id' => $environment->id]);
        $this->assertSame('locally_applied', $receipt->status);
        $this->assertNotNull($review->fresh()->applied_at);
        $review->update(['expires_at' => now()->subMinute()]);
        $retry = $transactions->run($review, $user, fn () => throw new RuntimeException('Writer must not run twice'));
        $this->assertSame($receipt->id, $retry->id);
        $this->assertDatabaseCount('configuration_applications', 1);
        $this->assertDatabaseCount('environments', 1);
        $this->expectException(AuthorizationException::class);
        $transactions->run($review, User::factory()->create(), fn () => null);
    }
}
