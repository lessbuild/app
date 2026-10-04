<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class RegionsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that an environment shows where it runs, and says so when that's more than one region.
     *
     * @return void
     */
    public function test_an_environment_shows_its_regions(): void
    {
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $cloud = Provider::factory()->create(['account_id' => $project->account_id, 'name' => 'Main cloud']);
        $git = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $place = function (string $region, string $name) use ($project, $environment, $cloud, $git): void {
            $website = Website::factory()->create(['name' => $name, 'server_id' => Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => $cloud->id, 'region' => $region])->id]);
            Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'environment_id' => $environment->id, 'provider_id' => $git->id]);
        };
        $page = "/api/app/projects/{$project->id}/deploy/environments/{$environment->id}";

        $this->actingAs($owner)->getJson($page)->assertOk()->assertJsonCount(0, 'placements');
        $place('fra1', 'Shop EU');
        $this->actingAs($owner)->getJson($page)->assertJsonCount(1, 'placements')->assertJsonPath('placements.0.name', 'Shop EU')->assertJsonPath('placements.0.region', 'fra1')->assertJsonPath('placements.0.provider', 'Main cloud');
        $place('nyc1', 'Shop US');
        $this->actingAs($owner)->getJson($page)->assertJsonCount(2, 'placements')->assertSee('nyc1');
    }
}
