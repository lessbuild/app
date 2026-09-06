<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationConfigurationPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_document_recreates_topology_with_independent_workspace_bindings(): void
    {
        config(['billing.enforce_entitlements' => false]);
        $yaml = <<<'YAML'
version: 2
environments:
  staging:
    type: staging
    placement: site
    runtime:
      type: node
      build_command: npm ci
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
      storage:
        type: object_storage
        managed: false
        variable_refs:
          AWS_SECRET_ACCESS_KEY: token
    variables:
      API_TOKEN:
        secret_ref: token
        scope: runtime
YAML;
        $topologies = [];
        $placementIds = [];
        foreach (['first-secret', 'second-secret'] as $index => $value) {
            $user = User::factory()->create();
            $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
            $server = $user->servers()->create(['name' => 'Test']);
            $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'Test', 'url' => 'test'.$index.'.example', 'description' => 'Test', 'environment' => '']);
            $sourceEnvironment = $project->environments()->create(['name' => 'Secrets', 'slug' => 'secrets', 'type' => 'staging']);
            $source = $sourceEnvironment->variables()->create(['key' => 'TOKEN', 'value' => $value, 'is_secret' => true, 'scope' => 'all', 'current_version' => 1, 'updated_by' => $user->id]);
            $review = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml,
                ['placements' => ['site' => $website->id], 'secrets' => ['token' => $source->id]]);
            app(ApplicationConfigurationReconciler::class)->apply($review, $user);
            $environment = $project->environments()->where('slug', 'staging')->firstOrFail();
            $placementIds[] = $environment->website_id;
            $this->assertSame($website->id, $environment->website_id);
            $this->assertSame($server->id, $environment->server_id);
            $variable = $environment->variables()->firstOrFail();
            $this->assertSame($value, $variable->value);
            $this->assertSame($value, $variable->versions()->firstOrFail()->value);
            $storage = $environment->resources()->where('name', 'storage')->firstOrFail();
            $this->assertSame($value, $storage->configuration['variables']['AWS_SECRET_ACCESS_KEY']);
            $this->assertStringNotContainsString($value, $storage->getRawOriginal('configuration'));
            $this->assertStringNotContainsString($value, json_encode($review->summary));
            $topologies[] = [
                'environment' => $environment->only(['slug', 'type', 'runtime_type', 'build_command', 'start_command', 'container_port', 'dockerfile_path']),
                'processes' => $environment->processes()->orderBy('name')->get()->map(fn ($record) => $record->only(['name', 'type', 'command', 'replicas', 'is_enabled']))->all(),
                'resources' => $environment->resources()->orderBy('name')->get()->map(fn ($record) => [
                    ...$record->only(['name', 'type', 'is_managed']), 'variable_keys' => array_keys($record->configuration['variables']),
                    'managed_configuration' => $record->is_managed ? $record->configuration : null,
                ])->all(),
                'variables' => $environment->variables()->orderBy('key')->get()->map(fn ($record) => $record->only(['key', 'scope', 'is_secret', 'current_version']))->all(),
            ];
        }
        $this->assertNotSame($placementIds[0], $placementIds[1]);
        $this->assertSame($topologies[0], $topologies[1]);
    }
}
