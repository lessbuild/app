<?php

namespace Tests\Feature;

use App\Jobs\Web\CheckWebsiteHealthJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\WebsiteHealthMonitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteHealthMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_consecutive_failures_create_one_outage_and_recovery_resets_it(): void
    {
        config(['lessbuild.health_monitor_failure_threshold' => 3]);
        [$owner, , $website] = $this->infrastructure();
        $command = null;

        foreach ([1, 2, 3] as $failureCount) {
            $this->app->instance(Runner::class, $this->runner(false, 'HTTP 503', $command));
            $this->assertSame(0, Artisan::call('lessbuild:websites:health', ['--website' => [$website->id]]));
            $website->refresh();
            $this->assertSame($failureCount, $website->health_failure_count);
            $this->assertSame(
                $failureCount === 3 ? Website::HEALTH_UNHEALTHY : Website::HEALTH_UNKNOWN,
                $website->health_status,
            );
            $this->assertSame('HTTP 503', $website->health_last_error);
            $this->assertNotNull($website->health_last_checked_at);
        }

        $this->assertSame(1, $owner->unreadNotifications()->count());
        $notification = $owner->unreadNotifications()->sole();
        $this->assertSame('website', $notification->data['category']);
        $this->assertSame('Website "application" is unhealthy', $notification->data['title']);
        $this->assertSame('HTTP 503', $notification->data['message']);
        $this->assertDatabaseHas('events', [
            'parentable_type' => Website::class,
            'parentable_id' => $website->id,
            'event' => 'Website "application" is unhealthy.',
        ]);

        $this->app->instance(Runner::class, $this->runner(false, 'HTTP 503 again', $command));
        Artisan::call('lessbuild:websites:health', ['--website' => [$website->id]]);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(4, $website->fresh()->health_failure_count);

        $this->app->instance(Runner::class, $this->runner(true, '', $command));
        Artisan::call('lessbuild:websites:health', ['--website' => [$website->id]]);
        $website->refresh();
        $this->assertSame(Website::HEALTH_HEALTHY, $website->health_status);
        $this->assertSame(0, $website->health_failure_count);
        $this->assertNull($website->health_last_error);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertDatabaseHas('events', [
            'parentable_type' => Website::class,
            'parentable_id' => $website->id,
            'event' => 'Website "application" recovered.',
        ]);

        $this->assertStringContainsString("'http://app.example.com/health/ready'", $command);
        $this->assertStringContainsString('--connect-timeout 5 --max-time 15', $command);
        $this->assertStringContainsString('--retry 1 --retry-delay 1 --retry-all-errors', $command);
        $this->assertStringContainsString('lessbuild-health-monitor', $command);
    }

    public function test_disabled_and_inactive_websites_are_not_contacted(): void
    {
        [, $server, $enabled] = $this->infrastructure();
        $enabled->update(['health_check_enabled' => false]);
        $inactive = $enabled->replicate()->fill([
            'name' => 'Inactive',
            'url' => 'inactive.example.com',
            'health_check_enabled' => true,
            'provisioning_status' => Website::STATUS_FAILED,
        ]);
        $inactive->user_id = $enabled->user_id;
        $inactive->server_id = $server->id;
        $inactive->deployment_slug = null;
        $inactive->save();
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        Artisan::call('lessbuild:websites:health', [
            '--website' => [$enabled->id, $inactive->id],
        ]);

        $this->assertStringContainsString('Checked 0 website(s)', Artisan::output());
        $this->assertNull($enabled->fresh()->health_last_checked_at);
        $this->assertNull($inactive->fresh()->health_last_checked_at);
    }

    public function test_recent_websites_are_skipped_until_the_next_monitoring_window(): void
    {
        [, , $website] = $this->infrastructure();
        $website->update([
            'health_status' => Website::HEALTH_HEALTHY,
            'health_last_checked_at' => now(),
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        Artisan::call('lessbuild:websites:health');
        $this->assertStringContainsString('Checked 0 website(s)', Artisan::output());

        $this->travel(5)->minutes();
        $command = null;
        $this->app->instance(Runner::class, $this->runner(true, '', $command));
        Artisan::call('lessbuild:websites:health');
        $this->assertStringContainsString('Checked 1 website(s)', Artisan::output());
    }

    public function test_result_started_before_a_health_setting_change_is_discarded(): void
    {
        [, , $website] = $this->infrastructure();
        $website->update([
            'health_status' => Website::HEALTH_HEALTHY,
            'health_last_checked_at' => now()->subMinutes(10),
        ]);
        $this->app->instance(Runner::class, $this->runnerDuringCheck(function () use ($website): void {
            $website->update([
                'health_check_path' => '/new-health',
                'health_status' => Website::HEALTH_UNKNOWN,
                'health_failure_count' => 0,
                'health_last_checked_at' => null,
                'health_last_error' => null,
            ]);
        }));

        Artisan::call('lessbuild:websites:health', ['--website' => [$website->id]]);

        $website->refresh();
        $this->assertSame('/new-health', $website->health_check_path);
        $this->assertSame(Website::HEALTH_UNKNOWN, $website->health_status);
        $this->assertSame(0, $website->health_failure_count);
        $this->assertNull($website->health_last_checked_at);
    }

    public function test_changing_or_disabling_health_settings_resets_monitoring_state(): void
    {
        [$owner, $server, $website] = $this->infrastructure();
        $website->update([
            'health_status' => Website::HEALTH_UNHEALTHY,
            'health_failure_count' => 3,
            'health_last_checked_at' => now(),
            'health_last_error' => 'Old outage',
        ]);

        $this->actingAs($owner)->patch(route('websites.update', $website), [
            ...$this->payload($server),
            'health_check_enabled' => '1',
            'health_check_path' => '/new-health',
        ])->assertRedirect(route('websites.show', $website));
        $website->refresh();
        $this->assertSame(Website::HEALTH_UNKNOWN, $website->health_status);
        $this->assertSame(0, $website->health_failure_count);
        $this->assertNull($website->health_last_checked_at);
        $this->assertNull($website->health_last_error);

        $website->update([
            'health_status' => Website::HEALTH_HEALTHY,
            'health_last_checked_at' => now(),
        ]);
        $this->actingAs($owner)->patch(route('websites.update', $website), [
            ...$this->payload($server),
            'health_check_enabled' => '0',
            'health_check_path' => '/new-health',
        ])->assertRedirect(route('websites.show', $website));
        $this->assertSame(Website::HEALTH_UNKNOWN, $website->fresh()->health_status);
        $this->assertNull($website->fresh()->health_last_checked_at);
    }

    public function test_health_state_and_failure_are_visible_to_the_owner(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $website->update([
            'health_status' => Website::HEALTH_UNHEALTHY,
            'health_failure_count' => 3,
            'health_last_checked_at' => now(),
            'health_last_error' => 'HTTP 502 from upstream',
        ]);

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Current health')
            ->assertSee('Unhealthy')
            ->assertSee('HTTP 502 from upstream');
        $this->actingAs($owner)->get(route('websites.index'))
            ->assertSuccessful()
            ->assertSee('Health')
            ->assertSee('unhealthy');
    }

    public function test_owner_can_queue_a_deduplicated_manual_health_check(): void
    {
        Queue::fake();
        [$owner, , $website] = $this->infrastructure();

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Check health now')
            ->assertSee(route('websites.health.check', $website));

        $this->actingAs($owner)->post(route('websites.health.check', $website))
            ->assertRedirect()
            ->assertSessionHas('success', 'Health check queued. Refresh shortly to see the result.');

        Queue::assertPushed(CheckWebsiteHealthJob::class, function (CheckWebsiteHealthJob $job) use ($website): bool {
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertSame((string) $website->id, $job->uniqueId());
            $this->assertSame(240, $job->uniqueFor);

            return $job->websiteId === $website->id;
        });
    }

    public function test_manual_health_check_requires_ownership_and_eligible_infrastructure(): void
    {
        Queue::fake();
        [$owner, $server, $website] = $this->infrastructure();

        $this->actingAs(User::factory()->create())
            ->post(route('websites.health.check', $website))
            ->assertForbidden();
        Queue::assertNothingPushed();

        $website->update(['health_check_enabled' => false]);
        $this->actingAs($owner)->post(route('websites.health.check', $website))
            ->assertRedirect()
            ->assertSessionHas('info', 'Enable health checks before requesting a manual check.');
        Queue::assertNothingPushed();

        $website->update(['health_check_enabled' => true]);
        $server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $this->actingAs($owner)->post(route('websites.health.check', $website))
            ->assertRedirect()
            ->assertSessionHas('info', 'The website and its server must be active before checking health.');
        Queue::assertNothingPushed();
    }

    public function test_manual_health_job_uses_the_same_monitoring_state_machine(): void
    {
        [, , $website] = $this->infrastructure();
        $command = null;
        $this->app->instance(Runner::class, $this->runner(true, '', $command));

        (new CheckWebsiteHealthJob($website->id))->handle(app(WebsiteHealthMonitor::class));

        $website->refresh();
        $this->assertSame(Website::HEALTH_HEALTHY, $website->health_status);
        $this->assertSame(0, $website->health_failure_count);
        $this->assertNotNull($website->health_last_checked_at);
        $this->assertStringContainsString("'http://app.example.com/health/ready'", $command);
    }

    private function runner(bool $successful, string $error, ?string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        if (! $successful) {
            $process->shouldReceive('getErrorOutput')->once()->andReturn($error);
        }

        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')
            ->once()
            ->withArgs(function (string $value) use (&$command): bool {
                $command = $value;

                return true;
            })
            ->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }

    private function runnerDuringCheck(callable $callback): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturnUsing(function () use ($callback, $process) {
            $callback();

            return $process;
        });
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }

    /** @return array{User, Server, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $owner->websites()->create([
            ...$this->payload($server),
            'health_check_enabled' => true,
            'health_check_path' => '/health/ready',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $server, $website];
    }

    /** @return array<string, mixed> */
    private function payload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Application',
            'url' => 'app.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'health_check_enabled' => '1',
            'health_check_path' => '/health/ready',
            'release_retention' => 5,
        ];
    }
}
