<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\DeployPipeline;
use App\Models\DeployPipelineRun;
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

final class PipelinesTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check a pipeline deploys its repositories in order, each after the one before succeeds, and a run stops when a
     * step fails; only one run at a time, and only this project's repositories.
     *
     * @return void
     */
    public function test_a_pipeline_deploys_repositories_in_order(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $this->onTier($project, 'deploy', 'pro');
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        [$api, $web] = array_map(fn (string $name): Repository => Repository::factory()->create([
            'project_id' => $project->id, 'name' => $name, 'environment_id' => $environment->id, 'provider_id' => $github->id,
            'website_id' => Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => $name, 'url' => $name.'.example.com'])->id,
        ]), ['api', 'web']);
        $foreign = Repository::factory()->create();
        $base = "/projects/{$project->id}/deploy/pipelines";

        $this->actingAs($owner)->post($base, ['name' => 'Lonely', 'steps' => [$api->id]])->assertSessionHasErrors('repository_ids');
        $this->actingAs($owner)->post($base, ['name' => 'Sneaky', 'steps' => [$api->id, $foreign->id]])->assertSessionHasErrors('repository_ids');
        $this->actingAs($owner)->post($base, ['name' => 'API then web', 'steps' => [$api->id, $web->id, '']])->assertRedirect();
        $pipeline = DeployPipeline::query()->sole();
        $this->assertSame([$api->id, $web->id], $pipeline->repository_ids);

        $this->actingAs($owner)->post("{$base}/{$pipeline->id}/run")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/{$pipeline->id}/run")->assertSessionHasErrors('pipeline');
        $first = Build::query()->where('repository_id', $api->id)->sole();
        $this->assertSame([$first->id], DeployPipelineRun::query()->sole()->build_ids);
        $this->assertFalse(Build::query()->where('repository_id', $web->id)->exists(), 'The web waits for the API.');

        app(FinishBuild::class)->handle($first, Build::STATUS_SUCCEEDED, null);
        $second = Build::query()->where('repository_id', $web->id)->sole();
        $this->assertSame('pipeline', $second->trigger_source);
        app(FinishBuild::class)->handle($second, Build::STATUS_SUCCEEDED, null);
        $run = DeployPipelineRun::query()->sole();
        $this->assertSame(['succeeded', [$first->id, $second->id]], [$run->status, $run->build_ids]);

        $this->actingAs($owner)->post("{$base}/{$pipeline->id}/run")->assertRedirect();
        $failing = Build::query()->where('repository_id', $api->id)->latest('id')->firstOrFail();
        app(FinishBuild::class)->handle($failing, Build::STATUS_FAILED, 'Tests failed');
        $stopped = DeployPipelineRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $stopped->status);
        $this->assertStringContainsString('api’s deploy #'.$failing->id.' failed', (string) $stopped->failure);
        $this->assertSame(1, Build::query()->where('repository_id', $web->id)->count(), 'The web wasn’t deployed after the API failed.');

        $this->actingAs($owner)->get($base)->assertOk()->assertSeeInOrder(['API then web', 'api', '→', 'web'])->assertSee('Failed');
    }
}
