<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ScheduledTaskStatusChanged;
use App\Services\Deploy\RepositoryDeploymentPlan;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AutomationTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Environment $production;

    private Website $website;

    private Repository $repository;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'business');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->website = Website::factory()->create(['server_id' => $server->id, 'name' => 'Shop', 'deployment_slug' => 'shop']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->production->forceFill(['minimum_replicas' => 1, 'maximum_replicas' => 4, 'desired_replicas' => 1])->save();
        $this->repository = Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $this->website->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id, 'name' => 'shop', 'url' => 'github.com/acme/shop']);
        $this->base = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Scheduled deploys run once when due in their time zone and respect locks.
     */
    public function test_scheduled_deploys_run_once_when_due_in_their_time_zone_and_respect_locks(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/deployment-schedules", ['name' => 'Nightly', 'cron_expression' => '0 3 * * *', 'timezone' => 'Mars/Olympus'])->assertJsonValidationErrors('cron_expression');
        $this->actingAs($this->owner)->postJson("{$this->base}/deployment-schedules", ['name' => 'Nightly', 'cron_expression' => '0 3 * * *', 'timezone' => 'America/New_York'])->assertSuccessful();
        $schedule = DeploymentSchedule::query()->sole();

        // 03:00 in New York in January is 08:00 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-01-05 03:00:00', 'UTC'));
        $this->command('automation:dispatch')->expectsOutput('Ran 0 scheduled deploys, 0 scaling schedules and 0 scheduled tasks.');
        $this->travelTo(CarbonImmutable::parse('2026-01-05 08:00:20', 'UTC'));
        $this->command('automation:dispatch')->expectsOutput('Ran 1 scheduled deploys, 0 scaling schedules and 0 scheduled tasks.');
        $this->command('automation:dispatch')->expectsOutput('Ran 0 scheduled deploys, 0 scaling schedules and 0 scheduled tasks.');
        $build = Build::query()->sole();
        $this->assertSame(['scheduled', $this->owner->id], [$build->trigger_source, $build->requested_by]);
        $this->assertSame("shop: deploy #{$build->id}", $schedule->refresh()->last_result);

        // The next day the deploy is still running; then the environment is locked.
        $this->travelTo(CarbonImmutable::parse('2026-01-06 08:00:00', 'UTC'));
        $this->command('automation:dispatch');
        $this->assertSame('shop: a deploy was already running', $schedule->refresh()->last_result);
        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => app(RepositoryDeploymentPlan::class)->finalStage()])->assertNoContent();
        $this->production->forceFill(['deployment_locked_at' => now(), 'deployment_lock_reason' => 'Black Friday'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-01-07 08:00:00', 'UTC'));
        $this->command('automation:dispatch');
        $this->assertStringContainsString('Black Friday', (string) $schedule->refresh()->last_result);
        $this->assertSame(1, Build::query()->count());

        $this->actingAs($this->owner)->getJson($this->base)->assertOk()
            ->assertJsonPath('deploySchedules.0.name', 'Nightly')->assertJsonPath('deploySchedules.0.timezone', 'America/New_York')
            ->assertJsonPath('deploySchedules.0.lastResult', fn (?string $result): bool => str_contains((string) $result, 'Black Friday'));
        $this->actingAs($this->owner)->deleteJson("{$this->base}/deployment-schedules/{$schedule->id}")->assertJsonRedirect("{$this->base}?tab=automation");
        $this->assertSame(0, DeploymentSchedule::query()->count());
    }

    /**
     * Scaling schedules set and apply replicas within the range.
     */
    public function test_scaling_schedules_set_and_apply_replicas_within_the_range(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/scaling-schedules", ['name' => 'Peak', 'replicas' => 9, 'cron_expression' => '0 8 * * 1-5', 'timezone' => 'UTC'])->assertJsonValidationErrors('replicas');
        $this->actingAs($this->owner)->postJson("{$this->base}/scaling-schedules", ['name' => 'Peak', 'replicas' => 3, 'cron_expression' => '0 8 * * 1-5', 'timezone' => 'UTC'])->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2026-01-05 08:00:00', 'UTC'));
        $this->command('automation:dispatch')->expectsOutput('Ran 0 scheduled deploys, 1 scaling schedules and 0 scheduled tasks.');
        $this->assertSame(3, $this->production->refresh()->desired_replicas);
        $command = $this->shell->ran[0]['command'] ?? '';
        $this->assertStringContainsString("done < '/var/www/shop/shared/processes/units'", $command);
        $this->assertStringContainsString('[ "$replica" -gt 3 ]', $command);
        $this->assertStringContainsString('systemctl enable --now "$unit"', $command);
        $this->assertSame('Scaled to 3 replicas', ScalingSchedule::query()->sole()->last_result);

        $this->onTier($this->project, 'deploy', 'pro');
        $this->actingAs($this->owner)->postJson("{$this->base}/scaling-schedules", ['name' => 'Night', 'replicas' => 1, 'cron_expression' => '0 20 * * *', 'timezone' => 'UTC'])->assertJsonValidationErrors('schedule');
    }

    /**
     * Scheduled tasks run in the release keep their history and alert on changes.
     */
    public function test_scheduled_tasks_run_in_the_release_keep_their_history_and_alert_on_changes(): void
    {
        Notification::fake();
        $other = Website::factory()->create(['server_id' => $this->website->server_id]);
        $task = ['name' => 'Prune', 'command' => "php artisan reports:prune --days='30'", 'cron_expression' => '*/15 * * * *', 'timezone' => 'UTC', 'timeout_seconds' => 120, 'without_overlapping' => '1', 'alert_on_failure' => '1'];
        $this->actingAs($this->owner)->postJson("{$this->base}/tasks", [...$task, 'website_id' => $other->id])->assertJsonValidationErrors('website_id');
        $this->actingAs($this->owner)->postJson("{$this->base}/tasks", [...$task, 'website_id' => $this->website->id])->assertSuccessful();
        $this->actingAs($this->owner)->postJson("{$this->base}/tasks", [...$task, 'website_id' => $this->website->id])->assertJsonValidationErrors('name');
        $scheduled = ScheduledTask::query()->sole();

        $this->shell->reply("pruned 12 reports\n");
        $this->travelTo(CarbonImmutable::parse('2026-01-05 08:15:00', 'UTC'));
        $this->command('automation:dispatch')->expectsOutput('Ran 0 scheduled deploys, 0 scaling schedules and 1 scheduled tasks.');
        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame(['succeeded', 'pruned 12 reports', 0, null], [$run->status, $run->output, $run->exit_code, $run->requested_by]);
        $script = $this->shell->ran[0]['command'];
        $this->assertStringContainsString("cd -- '/var/www/shop/current'", $script);
        $this->assertStringContainsString(base64_encode("php artisan reports:prune --days='30'"), $script);
        $this->assertStringContainsString('timeout --signal=TERM --kill-after=10 120', $script);
        Notification::assertNothingSent();

        // A failure alerts once, a second failure doesn't, and recovery alerts again.
        $this->shell->reply('SQLSTATE[HY000]', 1)->reply('SQLSTATE[HY000]', 1)->reply('ok');
        foreach (range(1, 3) as $attempt) {
            $this->actingAs($this->owner)->postJson("{$this->base}/tasks/{$scheduled->id}/run")->assertSuccessful();
        }
        Notification::assertSentToTimes($this->owner, ScheduledTaskStatusChanged::class, 2);
        $this->assertSame('succeeded', $scheduled->refresh()->last_status);
        $failed = ScheduledTaskRun::query()->where('status', 'failed')->firstOrFail();
        $this->assertSame([1, $this->owner->id], [$failed->exit_code, $failed->requested_by]);

        $this->actingAs($this->owner)->getJson("{$this->base}/tasks/{$scheduled->id}/runs/{$failed->id}")->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('SQLSTATE[HY000]');
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson("{$this->base}/tasks/{$scheduled->id}/runs/{$failed->id}")->assertForbidden();
        $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonPath('tasks.0.name', 'Prune')->assertJsonPath('canManage', false);

        // A run still going blocks another; history keeps the latest 50.
        $queued = new ScheduledTaskRun;
        $queued->forceFill(['scheduled_task_id' => $scheduled->id, 'status' => 'running'])->save();
        $this->actingAs($this->owner)->postJson("{$this->base}/tasks/{$scheduled->id}/run")->assertStatus(Response::HTTP_CONFLICT);
        $queued->forceFill(['status' => 'failed'])->save();
        foreach (range(1, 55) as $index) {
            $old = new ScheduledTaskRun;
            $old->forceFill(['scheduled_task_id' => $scheduled->id, 'status' => 'succeeded'])->save();
        }
        $this->actingAs($this->owner)->postJson("{$this->base}/tasks/{$scheduled->id}/run")->assertSuccessful();
        $this->assertSame(ScheduledTask::KEEP_RUNS, $scheduled->runs()->count());
    }

    /**
     * Idle environments hibernate and wake on the next request or deploy.
     */
    public function test_idle_environments_hibernate_and_wake_on_the_next_request_or_deploy(): void
    {
        $this->actingAs($this->owner)->putJson("{$this->base}/hibernation", ['hibernate_after_minutes' => 7])->assertJsonValidationErrors('hibernate_after_minutes');
        $this->actingAs($this->owner)->putJson("{$this->base}/hibernation", ['hibernate_after_minutes' => 15])->assertSuccessful();
        $this->assertSame(15, $this->production->refresh()->hibernate_after_minutes);

        // Too soon after the setting changed, then a request in the last 15 minutes, then nothing.
        $this->command('environments:hibernate')->expectsOutput('Hibernating 0 idle environments.');
        $this->assertCount(0, $this->shell->ran);
        $this->travel(20)->minutes();
        $this->shell->reply("/var/log/caddy/shop.access.log\n");
        $this->command('environments:hibernate')->expectsOutput('Hibernating 0 idle environments.');
        $this->assertStringContainsString("find '/var/log/caddy/shop.access.log' -mmin -15", $this->shell->ran[0]['command']);
        $this->travel(20)->minutes();
        $this->shell->reply('');
        $this->command('environments:hibernate')->expectsOutput('Hibernating 1 idle environments.');
        $this->assertStringContainsString('artisan" down --retry=60', $this->shell->ran[2]['command']);
        $this->assertStringContainsString('[ 1 = 1 ]', $this->shell->ran[2]['command']);
        $hibernatedAt = $this->production->refresh()->hibernated_at;
        $this->assertNotNull($hibernatedAt);
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('environment.hibernatedAt', $hibernatedAt->toIso8601String())->assertJsonPath('canManage', true);

        $this->travel(1)->minutes();
        $this->shell->reply((string) ($hibernatedAt->getTimestamp() - 5));
        $this->command('environments:wake')->expectsOutput('Waking 0 hibernated environments.');
        $this->shell->reply((string) ($hibernatedAt->getTimestamp() + 30));
        $this->command('environments:wake')->expectsOutput('Waking 1 hibernated environments.');
        $this->assertStringContainsString('artisan" up', $this->lastCommand());
        $this->assertNull($this->production->refresh()->hibernated_at);

        // By hand, and a deploy wakes it.
        $this->actingAs($this->owner)->postJson("{$this->base}/runtime", ['state' => 'hibernated'])->assertSuccessful();
        $this->assertNotNull($this->production->refresh()->hibernated_at);
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertSuccessful();
        $this->post(ProvisioningCallbackUrl::buildStatus(Build::query()->sole()), ['status' => app(RepositoryDeploymentPlan::class)->finalStage()])->assertNoContent();
        $this->assertNull($this->production->refresh()->hibernated_at);

        $this->onTier($this->project, 'deploy', 'free');
        $this->actingAs($this->owner)->putJson("{$this->base}/hibernation", ['hibernate_after_minutes' => 15])->assertJsonValidationErrors('hibernate_after_minutes');
        $this->actingAs($this->owner)->postJson("{$this->base}/runtime", ['state' => 'hibernated'])->assertJsonValidationErrors('state');
    }

    /**
     * The api changes runtime and scale and applies workflows.
     */
    public function test_the_api_changes_runtime_and_scale_and_applies_workflows(): void
    {
        $token = app(CreateApiToken::class)->handle($this->owner, $this->project->account, new CreateApiTokenData('ci', [ApiScope::DeployRead, ApiScope::DeployWrite], 30))->plainText;

        $this->api($token, 'PATCH', "/api/v1/environments/{$this->production->id}/runtime", ['state' => 'asleep'])->assertStatus(422);
        $this->api($token, 'PATCH', "/api/v1/environments/{$this->production->id}/runtime", ['state' => 'hibernated'])->assertStatus(202)->assertExactJson(['data' => ['status' => 'queued', 'state' => 'hibernated']]);
        $this->assertNotNull($this->production->refresh()->hibernated_at);
        $this->api($token, 'PATCH', "/api/v1/environments/{$this->production->id}/runtime", ['state' => 'running'])->assertStatus(202);
        $this->api($token, 'PATCH', "/api/v1/environments/{$this->production->id}/scale", ['replicas' => 2])->assertStatus(202);
        $this->assertStringContainsString('[ "$replica" -gt 2 ]', $this->lastCommand());

        $workflow = <<<'YAML'
        version: 1
        environments:
          production:
            deployment: { cron: '0 3 * * *', timezone: Europe/London }
            scale: { minimum: 1, maximum: 6, desired: 2, hibernate_after_minutes: 30 }
            scaling_schedules:
              - { name: Peak, replicas: 6, cron: '0 8 * * 1-5' }
            processes:
              queue: { type: worker, command: 'php artisan queue:work', replicas: 3 }
              scheduler: { type: scheduler, command: 'php artisan schedule:work', replicas: 4 }
        YAML;
        $this->api($token, 'PUT', "/api/v1/projects/{$this->project->id}/workflow", ['workflow' => $workflow])->assertOk()->assertExactJson(['data' => ['status' => 'applied']]);
        $this->production->refresh();
        $this->assertSame([1, 6, 2, 30], [$this->production->minimum_replicas, $this->production->maximum_replicas, $this->production->desired_replicas, $this->production->hibernate_after_minutes]);
        $this->assertSame(['Workflow schedule', '0 3 * * *', 'Europe/London'], [$this->production->deploymentSchedules()->sole()->name, $this->production->deploymentSchedules()->sole()->cron_expression, $this->production->deploymentSchedules()->sole()->timezone]);
        $this->assertSame(['Workflow: Peak', 6], [$this->production->scalingSchedules()->sole()->name, $this->production->scalingSchedules()->sole()->replicas]);
        $this->assertSame(['queue' => 3, 'scheduler' => 1], $this->production->processes()->pluck('replicas', 'name')->all());
        $this->assertSame($workflow, $this->project->refresh()->workflow_document);

        // Applying again replaces the workflow's scaling schedules; errors change nothing.
        $this->api($token, 'PUT', "/api/v1/projects/{$this->project->id}/workflow", ['workflow' => $workflow])->assertOk();
        $this->assertSame(1, $this->production->scalingSchedules()->count());
        $this->api($token, 'PUT', "/api/v1/projects/{$this->project->id}/workflow", ['workflow' => "version: 1\nenvironments:\n  production:\n    scale: { minimum: 1, maximum: 1 }\n  staging-nope: {}\n"])
            ->assertStatus(422)->assertJsonValidationErrors(['workflow' => 'Unknown environment: staging-nope.']);
        $this->assertSame(6, $this->production->refresh()->maximum_replicas);
        $this->api($token, 'PUT', "/api/v1/projects/{$this->project->id}/workflow", ['workflow' => "version: 2\nenvironments: {}\n"])->assertStatus(422);
        $this->api($token, 'PUT', "/api/v1/projects/{$this->project->id}/workflow", ['workflow' => "a: &x [1]\nb: *x\n"])->assertStatus(422);

        $this->onTier($this->project, 'deploy', 'starter');
        $this->api($token, 'PUT', "/api/v1/projects/{$this->project->id}/workflow", ['workflow' => $workflow])->assertStatus(422)->assertJsonValidationErrors('workflow');

        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/deploy/configuration")->assertOk()->assertJsonPath('workflow', $workflow);
    }

    /**
     * Get the last command run on a server.
     *
     * @return string
     */
    private function lastCommand(): string
    {
        $ran = $this->shell->ran;

        return $ran === [] ? '' : $ran[array_key_last($ran)]['command'];
    }

    /**
     * Call the API with a bearer token, forgetting any earlier authentication.
     *
     * @param  string  $token
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
