<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BuildLiveStatusTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * A running deploy is followed live and stops when it finishes.
     */
    public function test_a_running_deploy_is_followed_live_and_stops_when_it_finishes(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id, 'website_id' => Website::factory()->create(['server_id' => $server->id])->id]);
        $build = Build::factory()->create(['repository_id' => $repository->id, 'status' => Build::STATUS_RUNNING, 'setup_stage' => 2, 'started_at' => now(), 'log' => 'Cloning\\nInstalling\\n']);
        $base = "/api/app/projects/{$project->id}/deploy/builds/{$build->id}";

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('build.active', true)->assertJsonPath('build.setupStage', 2);
        $this->actingAs($owner)->getJson("{$base}/status")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['status' => 'running', 'stage' => 2, 'log' => 'Cloning\\nInstalling\\n', 'finished' => false]);

        $build->forceFill(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()])->save();
        $this->actingAs($owner)->getJson("{$base}/status")->assertJson(['status' => 'succeeded', 'finished' => true]);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('build.active', false);
        $this->actingAs(User::factory()->create())->getJson("{$base}/status")->assertNotFound();
    }
}
