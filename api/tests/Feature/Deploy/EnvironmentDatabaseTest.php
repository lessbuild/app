<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\EnvironmentResource;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class EnvironmentDatabaseTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * A managed MySQL resource uses the database it's told to, which shows on the Resources tab; without a choice it
     * takes the environment's own website that has a database (skipping one without), and a website outside the
     * account, or one without a database, can't be chosen.
     *
     * @return void
     */
    public function test_a_managed_mysql_resource_names_its_database(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $this->onTier($project, 'deploy', 'pro');
        $this->withoutMiddleware(RequirePassword::class);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $web = Website::factory()->create(['account_id' => $project->account_id, 'server_id' => $server->id, 'deployment_slug' => 'web', 'url' => 'example.com', 'has_database' => false]);
        $api = Website::factory()->create(['account_id' => $project->account_id, 'server_id' => $server->id, 'deployment_slug' => 'api', 'url' => 'api.example.com']);
        $data = Website::factory()->create(['account_id' => $project->account_id, 'server_id' => $server->id, 'deployment_slug' => 'data', 'url' => 'data.example.com', 'environment_id' => $production->id]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        foreach ([$web, $api] as $website) {
            Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'environment_id' => $production->id, 'provider_id' => $github->id]);
        }
        $base = "/api/app/projects/{$project->id}/deploy/environments/{$production->id}";

        $this->actingAs($owner)->postJson("{$base}/resources", ['name' => 'database', 'type' => 'mysql', 'is_managed' => '1'])->assertSuccessful();
        $this->assertSame('api', data_get(EnvironmentResource::query()->where('name', 'database')->sole()->configuration, 'variables.DB_DATABASE'), 'The Node site has no database, so the API’s is used.');

        $this->actingAs($owner)->postJson("{$base}/resources", ['name' => 'database', 'type' => 'mysql', 'is_managed' => '1', 'database_website_id' => $data->id])->assertSuccessful();
        $resource = EnvironmentResource::query()->where('name', 'database')->sole();
        $this->assertSame(['data', $data->id], [data_get($resource->configuration, 'variables.DB_DATABASE'), data_get($resource->configuration, 'database_website_id')]);

        $page = $this->actingAs($owner)->getJson($base)->assertOk();
        $page->assertJsonPath('resources.0.database', 'data');
        $this->assertSame([(string) $api->id, (string) $data->id], array_column((array) $page->json('databaseWebsites'), 'value'), 'The environment’s own come first; the Node site isn’t offered.');

        $foreign = Website::factory()->create(['url' => 'other.example.org']);
        $this->actingAs($owner)->postJson("{$base}/resources", ['name' => 'database', 'type' => 'mysql', 'is_managed' => '1', 'database_website_id' => $foreign->id])->assertJsonValidationErrors('database_website_id');
        $this->actingAs($owner)->postJson("{$base}/resources", ['name' => 'database', 'type' => 'mysql', 'is_managed' => '1', 'database_website_id' => $web->id])->assertJsonValidationErrors('database_website_id');
    }
}
