<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\ServerType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WebsiteDatabaseInUseTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check a website whose last deploy pointed it at another website's database says so, with a link to that
     * website's Database tab; one using its own database, or a database on another host, says nothing.
     *
     * @return void
     */
    public function test_a_website_using_another_websites_database_links_to_it(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure', 'deploy'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id, 'type' => ServerType::App]);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $site = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'my-shop', 'url' => 'shop.example.com', 'environment_id' => $production->id]);
        $api = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'api', 'url' => 'api.shop.example.com']);
        $repository = Repository::factory()->create(['website_id' => $api->id, 'project_id' => $project->id]);
        $base = "/api/app/projects/{$project->id}/infrastructure/websites/{$api->id}";
        $deploy = fn (array $variables, string $status = Build::STATUS_SUCCEEDED): Build => Build::factory()->create([
            'repository_id' => $repository->id, 'website_id' => $api->id, 'status' => $status,
            'environment_payload' => ['base_environment' => "APP_NAME=Shop\nDB_DATABASE=api\n", 'variables' => [], 'resources' => [['type' => 'mysql', 'configuration' => ['variables' => $variables]]]],
        ]);

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('website.databaseElsewhere', null);

        $deploy(['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'my_shop']);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('website.databaseElsewhere', [
            'name' => 'my_shop', 'website' => 'shop.example.com', 'url' => "/projects/{$project->id}/infrastructure/websites/{$site->id}?tab=database",
        ]);

        // A failed deploy after it doesn't change what the live release uses; a database elsewhere isn't on this server.
        $deploy(['DB_DATABASE' => 'api'], Build::STATUS_FAILED);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('website.databaseElsewhere.name', 'my_shop');
        $deploy(['DB_HOST' => 'db.example.com', 'DB_DATABASE' => 'warehouse']);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('website.databaseElsewhere', null);
        $deploy(['DB_HOST' => 'localhost', 'DB_DATABASE' => 'reports']);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('website.databaseElsewhere', ['name' => 'reports', 'website' => null, 'url' => null]);
    }
}
