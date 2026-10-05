<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DeliveryStatsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Deploy's front page says how delivery has gone: deploys this week by day, the success rate and median time, the
     * rollbacks, deploys waiting for approval, recent deploys, and whether each repository deploys pushes and previews.
     */
    public function test_the_repositories_page_sums_up_delivery(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => $provider->id])->id]);
        $repository = Repository::factory()->create(['name' => 'shop-app', 'website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $provider->id, 'environment_id' => $production->id, 'webhook_enabled' => true, 'previews_enabled' => false]);
        foreach ([30, 40, 50] as $seconds) {
            Build::factory()->succeeded()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'started_at' => now()->subSeconds($seconds), 'finished_at' => now()]);
        }
        Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_FAILED]);
        Build::factory()->succeeded()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'trigger_source' => 'rollback', 'started_at' => now()->subSeconds(40), 'finished_at' => now()]);
        $waiting = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_AWAITING_APPROVAL, 'commit_message' => 'Drop the old orders table']);

        $page = $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/deploy")->assertOk()
            ->assertJsonPath('repositories.0.pushDeploys', true)
            ->assertJsonPath('repositories.0.previews', false)
            ->assertJsonPath('stats.deploysThisWeek', 6)
            ->assertJsonPath('stats.daily.6', 6)
            ->assertJsonPath('stats.successRate', 80)
            ->assertJsonPath('stats.medianSeconds', 40)
            ->assertJsonPath('stats.rollbacks', 1)
            ->assertJsonPath('waiting.0.id', $waiting->id)
            ->assertJsonPath('waiting.0.commitMessage', 'Drop the old orders table')
            ->assertJsonCount(6, 'recent')
            ->assertJsonPath('recent.0.repository', 'shop-app')
            ->assertJsonPath('recent.0.production', true);
        $this->assertCount(7, (array) $page->json('stats.daily'));
    }
}
