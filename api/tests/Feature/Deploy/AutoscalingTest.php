<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\QueueSnapshot;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Website;
use App\Services\Deploy\Autoscaler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        $settings = fn (array $data) => $this->actingAs($owner)->putJson("/api/app/projects/{$project->id}/deploy/environments/{$environment->id}/settings", [
            'deployment_strategy' => 'rolling', 'rolling_pause_seconds' => 0, 'runtime_type' => 'php', 'minimum_replicas' => 1, 'maximum_replicas' => 1, 'desired_replicas' => 1, ...$data,
        ]);
        $settings(['autoscale_enabled' => '1'])->assertJsonValidationErrors('autoscale_enabled');
        $this->onTier($project, 'deploy', 'business');
        $settings(['autoscale_enabled' => '1', 'autoscale_cpu_target' => 60, 'minimum_replicas' => 1, 'maximum_replicas' => 3])->assertSuccessful();
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
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/deploy/environments/{$environment->id}")->assertJsonPath('environment.autoscaledAt', fn (?string $at): bool => $at !== null);
    }

    /**
     * Check replicas also follow a queue's waiting jobs (scaling up with idle CPU, and down once the queue drains),
     * and a hibernating website wakes the moment its server reports a request through the signed wake callback.
     *
     * @return void
     */
    public function test_replicas_follow_the_queue_and_hibernation_wakes_on_the_first_request(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy', 'infrastructure', 'monitoring'])->create();
        $this->onTier($project, 'deploy', 'business');
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'environment_id' => $environment->id, 'deployment_slug' => 'shop']);
        Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'environment_id' => $environment->id, 'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id])->id]);
        $environment->forceFill(['autoscale_enabled' => true, 'autoscale_cpu_target' => 60, 'autoscale_queue_jobs' => 100, 'minimum_replicas' => 1, 'maximum_replicas' => 4, 'desired_replicas' => 1])->save();
        $queue = Monitor::factory()->create(['environment_id' => $environment->id, 'type' => 'queue', 'queue_name' => 'default']);
        $report = function (int $pending) use ($queue): void {
            (new QueueSnapshot)->forceFill(['monitor_id' => $queue->id, 'snapshot_id' => (string) str()->uuid(), 'config_revision' => 0, 'payload_hash' => str()->random(40), 'observed_at' => now(), 'received_at' => now(), 'valid_until' => now()->addMinutes(5), 'applied' => true, 'pending' => $pending])->save();
        };

        $this->readings($server, 20, 6);
        $report(450);
        $this->assertSame([$environment->id => 2], app(Autoscaler::class)->run(), '450 jobs is more than 100 per replica, whatever the CPU.');
        $this->travel(4)->minutes();
        $this->readings($server, 20, 6);
        $report(450);
        app(Autoscaler::class)->run();
        $this->assertSame(3, $environment->refresh()->desired_replicas);
        $this->travel(11)->minutes();
        $this->readings($server, 20, 11);
        $report(40);
        app(Autoscaler::class)->run();
        $this->assertSame(2, $environment->refresh()->desired_replicas, 'A drained queue and calm CPU scale down.');

        $asleep = ApplyEnvironmentRuntime::script($website, true, 1);
        $this->assertStringContainsString('systemctl enable --now buildpusher-wake-shop.path', $asleep);
        $this->assertStringContainsString('buildpusher-wake-shop.service', $asleep);
        $this->assertStringContainsString('systemctl disable --now buildpusher-wake-shop.path', ApplyEnvironmentRuntime::script($website, false, 1));
        $this->assertSame(1, preg_match("#printf '%s' '([A-Za-z0-9+/=]+)' \\| base64 --decode > /etc/systemd/system/buildpusher-wake-shop.service#", $asleep, $service));
        $this->assertSame(1, preg_match("#curl -fsS -m 10 -X POST '([^']+)'#", base64_decode($service[1] ?? ''), $wake));

        Queue::fake();
        $environment->forceFill(['hibernated_at' => now()])->save();
        $this->post('/environments/'.$environment->id.'/wake')->assertForbidden();
        $this->post($wake[1] ?? '')->assertStatus(202);
        Queue::assertPushed(ApplyEnvironmentRuntime::class, fn (ApplyEnvironmentRuntime $job): bool => $job->environmentId === $environment->id && ! $job->hibernate);
    }
}
