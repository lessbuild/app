<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Website;
use App\Services\Deploy\Autoscaler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AutoscalingTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Record CPU readings for a server over the last few minutes.
     *
     * @param  Server  $server
     * @param  int  $cpu
     * @param  int  $minutes
     * @return void
     */
    private function readings(Server $server, int $cpu, int $minutes): void
    {
        ServerMetric::query()->where('server_id', $server->id)->delete();
        for ($minute = 0; $minute < $minutes; $minute++) {
            (new ServerMetric)->forceFill(['server_id' => $server->id, 'load_1m' => 1, 'load_5m' => 1, 'load_15m' => 1, 'cpu_percent' => $cpu, 'memory_percent' => 40, 'disk_percent' => 30, 'uptime_seconds' => 100, 'recorded_at' => now()->subMinutes($minute)->subSeconds(10)])->save();
        }
    }

    /**
     * Check that replicas follow the servers' CPU: up above the target, not again within the cooldown, down after
     * ten calm minutes, and never past the minimum or maximum; and that turning it on needs the scaling plan.
     *
     * @return void
     */
    public function test_replicas_follow_the_servers_cpu(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'environment_id' => $environment->id, 'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id])->id]);

        $settings = fn (array $data) => $this->actingAs($owner)->put("/projects/{$project->id}/deploy/environments/{$environment->id}/settings", [
            'deployment_strategy' => 'rolling', 'rolling_pause_seconds' => 0, 'runtime_type' => 'php', 'minimum_replicas' => 1, 'maximum_replicas' => 1, 'desired_replicas' => 1, ...$data,
        ]);
        $settings(['autoscale_enabled' => '1'])->assertSessionHasErrors('autoscale_enabled');
        $this->onTier($project, 'deploy', 'business');
        $settings(['autoscale_enabled' => '1', 'autoscale_cpu_target' => 60, 'minimum_replicas' => 1, 'maximum_replicas' => 3])->assertSessionHasNoErrors();
        $environment->refresh();
        $this->assertSame([true, 60], [$environment->autoscale_enabled, $environment->autoscale_cpu_target]);

        $this->readings($server, 90, 6);
        $this->assertSame([$environment->id => 2], app(Autoscaler::class)->run());
        $this->assertSame([], app(Autoscaler::class)->run(), 'Not again within three minutes.');
        $this->travel(4)->minutes();
        $this->readings($server, 90, 6);
        app(Autoscaler::class)->run();
        $this->travel(4)->minutes();
        $this->readings($server, 95, 6);
        app(Autoscaler::class)->run();
        $this->assertSame(3, $environment->refresh()->desired_replicas, 'Never past the maximum.');

        $this->readings($server, 10, 11);
        $this->assertSame([], app(Autoscaler::class)->run(), 'Scaling down waits ten minutes after the last change.');
        $this->travel(11)->minutes();
        $this->readings($server, 10, 11);
        $this->assertSame([$environment->id => 2], app(Autoscaler::class)->run());
        $this->assertStringContainsString('Last scaled automatically', (string) $this->actingAs($owner)->get("/projects/{$project->id}/deploy/environments/{$environment->id}?tab=settings")->getContent());
    }
}
