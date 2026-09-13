<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\User;
use App\Services\ApplicationConfigurationEnvironmentOverviewQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationConfigurationEnvironmentOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_reports_recorded_dependencies_without_decrypting_sensitive_configuration(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Storefront', 'slug' => 'storefront', 'created_by' => $owner->id,
        ]);
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => 'github', 'token' => 'provider-secret', 'description' => 'Source control',
        ]);
        $server = $owner->servers()->create(['name' => 'Application server', 'provisioning_status' => 'active']);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Storefront site', 'url' => 'storefront.test',
            'description' => 'Application', 'environment' => '', 'provisioning_status' => 'active',
            'health_status' => 'healthy',
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Storefront source',
            'url' => 'github.com/example/storefront.git', 'branch' => 'develop', 'description' => 'Source',
        ]);
        $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $environment = $project->environments()->create([
            'server_id' => $server->id, 'website_id' => $website->id, 'name' => 'Staging', 'slug' => 'staging',
            'type' => 'staging', 'branch' => 'develop', 'runtime_type' => 'node', 'status' => 'ready',
        ]);
        $environment->processes()->create([
            'name' => 'worker', 'type' => 'worker', 'command' => 'private-worker-command', 'replicas' => 2,
            'is_enabled' => true,
        ]);
        $resource = $environment->resources()->create([
            'name' => 'cache', 'type' => 'redis', 'is_managed' => false, 'status' => 'ready',
            'configuration' => ['password' => 'private-resource-password'],
        ]);
        $secret = $environment->variables()->create([
            'key' => 'PRIVATE_TOKEN', 'value' => 'private-variable-value', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 4, 'updated_by' => $owner->id,
        ]);
        DB::table('environment_variables')->where('id', $secret->id)->update(['value' => 'unreadable-ciphertext']);

        $foreign = User::factory()->create();
        $foreignProject = $foreign->currentOrganization->projects()->create([
            'name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $foreign->id,
        ]);
        $foreignProject->environments()->create([
            'name' => 'Foreign environment', 'slug' => 'foreign', 'type' => 'staging',
        ]);

        $overview = app(ApplicationConfigurationEnvironmentOverviewQuery::class)->for($project);

        $this->assertCount(1, $overview);
        $summary = $overview->sole();
        $this->assertSame($environment->id, $summary->id);
        $this->assertSame('Staging', $summary->name);
        $this->assertSame('node', $summary->runtimeType);
        $this->assertSame(1, $summary->processCount);
        $this->assertSame(1, $summary->resourceCount);
        $this->assertSame(1, $summary->variableCount);
        $this->assertSame(1, $summary->secretCount);
        $serialized = json_encode($summary);
        $this->assertIsString($serialized);
        $this->assertStringContainsString('Storefront source', $serialized);
        $this->assertStringContainsString('succeeded', $serialized);
        $this->assertStringNotContainsString('private-worker-command', $serialized);
        $this->assertStringNotContainsString('private-resource-password', $serialized);
        $this->assertStringNotContainsString('private-variable-value', $serialized);
        $this->assertStringNotContainsString('unreadable-ciphertext', $serialized);
        $this->assertStringNotContainsString('Foreign environment', $serialized);

        $this->assertSame('enabled', collect($summary->dependencies)->firstWhere('kind', 'process')['status']);
        $this->assertStringContainsString('External', collect($summary->dependencies)->firstWhere('kind', 'resource')['detail']);
    }

    public function test_eager_loaded_overview_query_count_does_not_grow_with_more_environments(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Storefront', 'slug' => 'storefront', 'created_by' => $owner->id,
        ]);
        $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production']);

        $query = app(ApplicationConfigurationEnvironmentOverviewQuery::class);
        DB::enableQueryLog();
        try {
            $query->for($project);
            $oneEnvironmentQueries = count(DB::getQueryLog());
            DB::flushQueryLog();
            $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging']);
            DB::flushQueryLog();
            $query->for($project);
            $twoEnvironmentQueries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame($oneEnvironmentQueries, $twoEnvironmentQueries);
    }
}
