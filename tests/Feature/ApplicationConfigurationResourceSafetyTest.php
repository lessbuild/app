<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ConfigureResourcesScript;
use App\Scripts\Repository\SyncEnvironmentScript;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use App\Services\DeploymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApplicationConfigurationResourceSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_secret_references_are_encrypted_preserved_cleared_and_snapshotted(): void
    {
        [$user, $project, $website, $repository, $source] = $this->fixture();
        $document = $this->document(['storage' => ['type' => 'object_storage', 'managed' => false, 'variable_refs' => ['AWS_SECRET_ACCESS_KEY' => 'token']]]);
        $bindings = ['placements' => ['site' => $website->id], 'secrets' => ['token' => $source->id], 'repositories' => ['app' => $repository->id]];
        $document['environments']['staging']['deploy'] = ['repository' => 'app'];
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, Yaml::dump($document, 10), $bindings);
        $this->assertDatabaseCount('environment_resources', 0);
        $application = app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        $environment = $project->environments()->where('slug', 'staging')->firstOrFail();
        $resource = $environment->resources()->firstOrFail();
        $this->assertSame(['AWS_SECRET_ACCESS_KEY' => 'external-secret'], $resource->configuration['variables']);
        $ciphertext = $resource->getRawOriginal('configuration');
        $operation = $application->operations()->firstOrFail();
        $snapshot = $operation->payload['attributes']['environment_payload']['resources'][0];
        $this->assertFalse($snapshot['is_managed']);
        $this->assertSame($resource->configuration, $snapshot['configuration']);
        foreach ([$ciphertext, $operation->getRawOriginal('payload'), json_encode($review->summary), json_encode($resource->toArray())] as $output) {
            $this->assertStringNotContainsString('external-secret', $output);
        }
        unset($document['environments']['staging']['deploy'], $document['environments']['staging']['resources']['storage']['variable_refs']);
        $preserved = app(ApplicationConfigurationReviews::class)->create($project, $user, Yaml::dump($document, 10), $bindings);
        app(ApplicationConfigurationReconciler::class)->apply($preserved, $user);
        $this->assertSame($ciphertext, $resource->fresh()->getRawOriginal('configuration'));
        $document['environments']['staging']['resources']['storage']['variable_refs'] = [];
        app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, Yaml::dump($document, 10), $bindings), $user);
        $this->assertSame([], $resource->fresh()->configuration['variables']);
        $this->assertSame('external-secret', $operation->fresh()->payload['attributes']['environment_payload']['resources'][0]['configuration']['variables']['AWS_SECRET_ACCESS_KEY']);
        $this->assertDatabaseCount('environment_resources', 1);
        Queue::assertNothingPushed();
    }

    public function test_external_credentials_reject_foreign_build_only_and_changed_secret_sources(): void
    {
        [$user, $project, $website, , $source] = $this->fixture();
        $yaml = Yaml::dump($this->document(['storage' => ['type' => 'object_storage', 'managed' => false, 'variable_refs' => ['AWS_SECRET_ACCESS_KEY' => 'token']]]), 10);
        $bindings = ['placements' => ['site' => $website->id], 'secrets' => ['token' => $source->id]];
        $source->update(['scope' => 'build']);
        $this->assertReviewRejected(fn () => app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), 'bindings');
        $source->update(['scope' => 'all']);
        $other = User::factory()->create();
        $foreignProject = $other->currentOrganization->projects()->create(['name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $other->id]);
        $sourceEnvironment = $source->environment;
        $originalProject = $sourceEnvironment->project_id;
        $sourceEnvironment->update(['project_id' => $foreignProject->id]);
        $this->assertReviewRejected(fn () => app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), 'bindings');
        $sourceEnvironment->update(['project_id' => $originalProject]);
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings);
        $source->update(['value' => 'rotated-secret']);
        $this->assertReviewRejected(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $user), 'review');
        $this->assertDatabaseCount('environment_resources', 0);
        $this->assertDatabaseCount('configuration_applications', 0);
        $this->assertNull($review->fresh()->applied_at);
    }

    public function test_managed_resources_snapshot_management_and_keep_generated_credentials_encrypted(): void
    {
        [$user, $project, $website, $repository] = $this->fixture();
        foreach (['mysql', 'postgresql', 'redis', 'valkey'] as $type) {
            $yaml = Yaml::dump($this->document([$type => ['type' => $type, 'managed' => true]]), 10);
            $review = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, ['placements' => ['site' => $website->id]]);
            app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        }
        $environment = $project->environments()->where('slug', 'staging')->firstOrFail();
        $resources = $environment->resources()->get()->keyBy('name');
        foreach (['mysql', 'postgresql'] as $type) {
            $this->assertSame('website-secret', $resources[$type]->configuration['variables']['DB_PASSWORD']);
            $this->assertSame($website->databaseIdentifier(), $resources[$type]->configuration['variables']['DB_DATABASE']);
            $this->assertStringNotContainsString('website-secret', $resources[$type]->getRawOriginal('configuration'));
        }
        $this->assertSame('198.51.100.4', $resources['mysql']->configuration['variables']['DB_HOST']);
        $this->assertSame('127.0.0.1', $resources['postgresql']->configuration['variables']['DB_HOST']);
        $this->assertSame('6379', $resources['redis']->configuration['variables']['REDIS_PORT']);
        $this->assertSame((string) (16379 + $environment->id % 10000), $resources['valkey']->configuration['variables']['VALKEY_PORT']);
        $snapshot = app(DeploymentRequest::class)->attributesForEnvironment($repository, $environment, $user)['environment_payload'];
        $this->assertCount(4, $snapshot['resources']);
        $this->assertSame([true], array_values(array_unique(array_column($snapshot['resources'], 'is_managed'))));
        $script = $this->script($snapshot['resources']);
        $this->assertStringContainsString('redis-server', $script);
        $this->assertStringContainsString('valkey/valkey:8-alpine', $script);
        $this->assertStringContainsString('ALTER ROLE', $script);
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script)->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
        Queue::assertNothingPushed();
    }

    public function test_external_loopback_credentials_never_request_managed_provisioning_and_legacy_snapshots_remain_supported(): void
    {
        $resources = [
            ['type' => 'redis', 'configuration' => ['variables' => ['REDIS_HOST' => '127.0.0.1']]],
            ['type' => 'postgresql', 'configuration' => ['variables' => ['DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'application', 'DB_USERNAME' => 'application', 'DB_PASSWORD' => 'external-secret']]],
            ['type' => 'valkey', 'configuration' => ['container_name' => 'buildpusher-valkey-1-cache', 'variables' => ['VALKEY_PORT' => '16380']]],
        ];
        $external = array_map(fn ($resource) => ['is_managed' => false, ...$resource], $resources);
        $script = $this->script($external);
        foreach (['apt-get', 'systemctl', 'docker run', 'ALTER ROLE', 'external-secret'] as $action) {
            $this->assertStringNotContainsString($action, $script);
        }
        $legacyScript = $this->script($resources);
        foreach (['redis-server', 'ALTER ROLE', 'docker run'] as $action) {
            $this->assertStringContainsString($action, $legacyScript);
        }
    }

    public function test_managed_database_identifier_changes_require_a_new_review(): void
    {
        [$user, $project, $website] = $this->fixture();
        $yaml = Yaml::dump($this->document(['database' => ['type' => 'mysql', 'managed' => true]]), 10);
        $bindings = ['placements' => ['site' => $website->id]];
        $reviews = app(ApplicationConfigurationReviews::class);
        $review = $reviews->create($project, $user, $yaml, $bindings);
        $website->update(['deployment_slug' => 'changed-application']);

        $this->assertReviewRejected(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $user), 'review');
        $this->assertDatabaseCount('configuration_applications', 0);
        $this->assertDatabaseCount('environment_resources', 0);
        $this->assertNull($review->fresh()->applied_at);

        app(ApplicationConfigurationReconciler::class)->apply($reviews->create($project, $user, $yaml, $bindings), $user);
        $variables = $project->environments()->firstOrFail()->resources()->firstOrFail()->configuration['variables'];
        $this->assertSame('changed_application', $variables['DB_DATABASE']);
        $this->assertSame('changed_application', $variables['DB_USERNAME']);
    }

    public function test_deployment_preserves_website_environment_and_secret_references_without_public_disclosure(): void
    {
        [$user, $project, $website, $repository, $source] = $this->fixture();
        $website->update(['environment' => "BASE_TOKEN=reviewed-base-secret\nAPI_TOKEN=overridden-base-secret"]);
        $document = $this->document(['storage' => ['type' => 'object_storage', 'managed' => false, 'variable_refs' => ['AWS_SECRET_ACCESS_KEY' => 'token']]]);
        $document['environments']['staging']['variables'] = ['API_TOKEN' => ['secret_ref' => 'token', 'scope' => 'runtime']];
        $document['environments']['staging']['deploy'] = ['repository' => 'app'];
        $bindings = ['placements' => ['site' => $website->id], 'secrets' => ['token' => $source->id], 'repositories' => ['app' => $repository->id]];
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, Yaml::dump($document, 10), $bindings);
        $application = app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        $operation = $application->operations()->firstOrFail();
        $build = $repository->builds()->create([...$operation->payload['attributes'], 'trigger_source' => Build::TRIGGER_API]);

        $website->update(['environment' => 'BASE_TOKEN=unreviewed-base-secret']);
        $source->update(['value' => 'unreviewed-reference-secret']);
        $build->refresh();
        $contents = $this->syncedEnvironment($build);
        $this->assertStringContainsString('BASE_TOKEN=reviewed-base-secret', $contents);
        $this->assertStringContainsString('API_TOKEN="external-secret"', $contents);
        $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY="external-secret"', $contents);
        $this->assertStringNotContainsString('overridden-base-secret', $contents);
        $this->assertStringNotContainsString('unreviewed-', $contents);
        $this->assertArrayNotHasKey('environment_payload', $build->toArray());
        Sanctum::actingAs($user, ['manage']);
        $receipt = $this->getJson('/api/v1/projects/'.$project->id.'/configuration/applications/'.$application->id)->assertOk()->assertJsonMissingPath('data.base_environment');
        foreach ([$build->toJson(), $build->getRawOriginal('environment_payload'), $operation->toJson(), $operation->getRawOriginal('payload'), json_encode($review->summary), $receipt->getContent()] as $output) {
            foreach (['reviewed-base-secret', 'external-secret', 'base_environment'] as $private) {
                $this->assertStringNotContainsString($private, $output);
            }
        }
    }

    public function test_empty_website_environment_snapshot_stays_empty_and_legacy_payload_keeps_its_fallback(): void
    {
        [$user, $project, $website, $repository] = $this->fixture();
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, Yaml::dump($this->document([]), 10), ['placements' => ['site' => $website->id]]);
        app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        $environment = $project->environments()->firstOrFail();
        $attributes = app(DeploymentRequest::class)->attributesForEnvironment($repository, $environment, $user);
        $this->assertSame('', $attributes['environment_payload']['base_environment']);
        $website->update(['environment' => 'LATE_TOKEN=unreviewed-secret']);
        $build = $repository->builds()->create([...$attributes, 'trigger_source' => Build::TRIGGER_API]);
        $this->assertSame('', $this->syncedEnvironment($build));

        $payload = $attributes['environment_payload'];
        unset($payload['base_environment']);
        $build->update(['environment_payload' => $payload]);
        $this->assertSame('LATE_TOKEN=unreviewed-secret', $this->syncedEnvironment($build->fresh()));
    }

    public function test_shared_placement_keeps_managed_credential_fingerprint_regardless_of_environment_order(): void
    {
        [$user, $project, $website] = $this->fixture();
        $document = $this->document(['database' => ['type' => 'postgresql', 'managed' => true]]);
        $document['environments']['development'] = ['type' => 'development', 'placement' => 'site', 'runtime' => ['type' => 'php']];
        $bindings = ['placements' => ['site' => $website->id]];
        $reviews = app(ApplicationConfigurationReviews::class);

        foreach ([false, true] as $reverse) {
            if ($reverse) {
                $document['environments'] = array_reverse($document['environments'], true);
            }
            $review = $reviews->create($project, $user, Yaml::dump($document, 10), $bindings);
            $website->update(['database_password' => $reverse ? 'second-rotation' : 'first-rotation']);
            $this->assertReviewRejected(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $user), 'review');
            $this->assertDatabaseCount('configuration_applications', 0);
            $this->assertDatabaseCount('environment_resources', 0);
            $this->assertNull($review->fresh()->applied_at);
        }
    }

    private function fixture(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test', 'public_ip' => '198.51.100.4', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '', 'database_password' => 'website-secret', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'provider-secret', 'description' => 'Git']);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $sources = $user->currentOrganization->projects()->create(['name' => 'Sources', 'slug' => 'sources', 'created_by' => $user->id]);
        $sourceEnvironment = $sources->environments()->create(['name' => 'Secrets', 'slug' => 'secrets', 'type' => 'staging']);
        $source = $sourceEnvironment->variables()->create(['key' => 'TOKEN', 'value' => 'external-secret', 'is_secret' => true, 'scope' => 'all', 'current_version' => 1, 'updated_by' => $user->id]);

        return [$user, $project, $website, $repository, $source];
    }

    private function document(array $resources): array
    {
        return ['version' => 2, 'environments' => ['staging' => ['type' => 'staging', 'placement' => 'site', 'runtime' => ['type' => 'php'], 'resources' => $resources]]];
    }

    private function script(array $resources): string
    {
        $build = new Build(['environment_payload' => ['resources' => $resources]]);
        $build->id = 42;

        return (new ConfigureResourcesScript)->script(3, $build);
    }

    private function syncedEnvironment(Build $build): string
    {
        $script = (new SyncEnvironmentScript)->script(1, $build);
        $this->assertSame(1, preg_match("/printf '%s' '([^']*)' \\| base64 --decode/", $script, $matches));

        return base64_decode($matches[1], true);
    }

    private function assertReviewRejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Unsafe resource configuration was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertStringNotContainsString('secret', json_encode($exception->errors()));
        }
    }
}
