<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerAlertRule;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ServerAlertChanged;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class InfrastructureHealthTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure', 'monitoring'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Website health checks run as monitoring monitors.
     */
    public function test_website_health_checks_run_as_monitoring_monitors(): void
    {
        $environment = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $base = "/api/app/projects/{$this->project->id}/infrastructure/websites";

        $this->actingAs($this->owner)->postJson($base, [
            'name' => 'Shop', 'server_id' => $this->server->id, 'url' => 'shop.example.com', 'environment_id' => $environment->id,
            'health_check_enabled' => '1', 'health_check_path' => '/up', 'health_check_interval_minutes' => 15, 'health_failure_threshold' => 2,
        ])->assertSuccessful();

        $website = Website::query()->sole();
        $monitor = Monitor::query()->sole();
        $this->assertSame($monitor->id, $website->health_monitor_id);
        $this->assertSame(['http', 'https://shop.example.com/up', 15, 2, $environment->id], [$monitor->type, $monitor->request_url, $monitor->interval_minutes, $monitor->trigger_checks, $monitor->environment_id]);
        $this->actingAs($this->owner)->getJson("{$base}/{$website->id}")->assertOk()->assertJsonPath('health.monitor.projectId', $this->project->id);
        $website->forceFill(['provisioning_status' => Website::STATUS_ACTIVE])->save();

        $this->actingAs($this->owner)->putJson("{$base}/{$website->id}", ['name' => 'Shop', 'server_id' => $this->server->id, 'url' => 'shop.example.com', 'environment_id' => $environment->id, 'health_check_enabled' => '0'])->assertSuccessful()->assertSuccessful();
        $this->assertSoftDeleted($monitor);
        $this->assertNull($this->reload($website)->health_monitor_id);
        $this->actingAs($this->owner)->getJson("{$base}/{$website->id}")->assertJsonPath('website.healthCheckEnabled', false)->assertJsonPath('health.monitor', null);

        $this->actingAs($this->owner)->putJson("{$base}/{$website->id}", ['name' => 'Shop', 'server_id' => $this->server->id, 'url' => 'shop.example.com', 'environment_id' => '', 'health_check_enabled' => '1'])->assertSuccessful();
        $this->assertSame(1, Monitor::withTrashed()->count());
        $this->actingAs($this->owner)->getJson("{$base}/{$website->id}")->assertJsonPath('health.environment', null);
        $this->actingAs($this->owner)->putJson("{$base}/{$website->id}", ['name' => 'Shop', 'server_id' => $this->server->id, 'url' => 'shop.example.com', 'environment_id' => \App\Models\Environment::factory()->create()->id])->assertJsonValidationErrors('environment_id');
    }

    /**
     * Server alerts trip after consecutive readings and recover.
     */
    public function test_server_alerts_trip_after_consecutive_readings_and_recover(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $this->addMember($this->project, $admin, AccountRole::Admin);
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $base = "/api/app/projects/{$this->project->id}/infrastructure/servers/{$this->server->id}";
        $this->actingAs($this->owner)->postJson("{$base}/alerts", ['name' => 'Disk almost full', 'metric' => 'disk_percent', 'operator' => 'gte', 'threshold' => 90, 'consecutive_breaches' => 2, 'cooldown_minutes' => 60, 'scope' => 'server'])->assertSuccessful();
        $rule = ServerAlertRule::query()->sole();

        foreach ([95, 96, 97] as $disk) {
            $this->reading($disk);
        }
        $this->assertTrue($this->reload($rule)->is_alerting);
        Notification::assertSentToTimes($this->owner, ServerAlertChanged::class, 1);
        Notification::assertSentTo($admin, ServerAlertChanged::class, fn (ServerAlertChanged $notification): bool => $notification->toArray($admin)['title'] === 'Disk almost full on '.$this->server->label());
        Notification::assertNotSentTo($member, ServerAlertChanged::class);

        $this->reading(40);
        $this->assertFalse($this->reload($rule)->is_alerting);
        Notification::assertSentToTimes($this->owner, ServerAlertChanged::class, 2);

        // Tripping again inside the cooldown stays quiet.
        $this->reading(95);
        $this->reading(95);
        Notification::assertSentToTimes($this->owner, ServerAlertChanged::class, 2);

        $this->actingAs($this->owner)->getJson($base)->assertJsonPath('alertRules.0.name', 'Disk almost full')->assertJsonPath('alertRules.0.everyServer', false);
        $this->actingAs($member)->postJson("{$base}/alerts", ['name' => 'x', 'metric' => 'cpu_percent', 'operator' => 'gte', 'threshold' => 1, 'consecutive_breaches' => 1, 'cooldown_minutes' => 5, 'scope' => 'account'])->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("{$base}/alerts/{$rule->id}")->assertSuccessful();
        $this->assertModelMissing($rule);
    }

    private function reading(int $disk): void
    {
        $this->shell->reply("load_1m=0.1\nload_5m=0.1\nload_15m=0.1\nmemory_percent=40\ndisk_percent={$disk}\nuptime_seconds=100\ncpu_percent=5\nprocess_count=50\n");
        app(\App\Services\Infrastructure\ServerMetricsCollector::class)->collect($this->server);
    }
}
