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

    /**
     * The website holding the data names the websites whose settings use it, and a database nothing uses says so;
     * a website created without a database has no Database tab data to show and sets none up.
     *
     * @return void
     */
    public function test_a_database_says_who_uses_it(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure', 'deploy'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id, 'type' => ServerType::App]);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $site = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'vacenly', 'url' => 'vacenly.com', 'environment_id' => $production->id]);
        $api = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'api', 'url' => 'api.vacenly.com', 'environment_id' => $production->id]);
        $repository = Repository::factory()->create(['website_id' => $api->id, 'project_id' => $project->id]);
        $page = fn (Website $website): array => (array) $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/infrastructure/websites/{$website->id}")->assertOk()->json('website');
        $show = fn (Website $website) => $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/infrastructure/websites/{$website->id}")->assertOk();

        $show($site)->assertJsonPath('website.databaseUnused', true);
        Build::factory()->create(['repository_id' => $repository->id, 'website_id' => $api->id, 'status' => Build::STATUS_SUCCEEDED, 'environment_payload' => [
            'base_environment' => '', 'variables' => [], 'resources' => [['type' => 'mysql', 'configuration' => ['variables' => ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'vacenly']]]],
        ]]);

        $vacenly = $page($site);
        $show($site)->assertJsonPath('website.databaseUnused', false);
        $this->assertSame([['website' => 'api.vacenly.com', 'url' => "/projects/{$project->id}/infrastructure/websites/{$api->id}"]], $vacenly['databaseUsedBy']);
        $this->assertSame('vacenly', $page($api)['databaseElsewhere']['name'], 'The API’s own empty database points the other way.');

        $static = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'docs', 'url' => 'docs.vacenly.com', 'has_database' => false]);
        $show($static)->assertJsonPath('website.hasDatabase', false)->assertJsonPath('website.databaseUnused', false);
        $script = app(\App\Services\Infrastructure\Scripts\Website\CreateMysqlDatabaseScript::class)->script(2, $static);
        $this->assertStringNotContainsString('CREATE DATABASE', $script);
    }
}
