<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Models\Build;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class EnvironmentsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Environment $production;

    private Repository $repository;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop', 'url' => 'shop.example.com']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->repository = Repository::factory()->create([
            'project_id' => $this->project->id, 'website_id' => $website->id, 'environment_id' => $this->production->id,
            'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id])->id,
        ]);
        $this->base = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Variables processes and resources travel with each build.
     */
    public function test_variables_processes_and_resources_travel_with_each_build(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/variables", ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_1', 'scope' => 'runtime', 'is_secret' => '1'])->assertSuccessful();
        $this->actingAs($this->owner)->postJson("{$this->base}/variables", ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_2', 'scope' => 'all', 'is_secret' => '1']);
        $this->actingAs($this->owner)->postJson("{$this->base}/variables", ['key' => 'VITE_APP_NAME', 'value' => 'Shop', 'scope' => 'build']);
        $this->actingAs($this->owner)->postJson("{$this->base}/variables", ['key' => 'bad-key', 'value' => 'x', 'scope' => 'runtime'])->assertJsonValidationErrors('key');
        $secret = EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->sole();
        $this->assertSame([2, 'sk_live_2', 2], [$secret->current_version, $secret->value, $secret->versions()->count()]);
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertSee('STRIPE_SECRET')->assertDontSee('sk_live_2')->assertSee('Shop');

        $this->actingAs($this->owner)->postJson("{$this->base}/processes", ['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 2, 'restart_policy' => 'always', 'restart_delay_seconds' => 5])->assertSuccessful();
        $this->actingAs($this->owner)->postJson("{$this->base}/processes", ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work', 'replicas' => 4, 'restart_policy' => 'always', 'restart_delay_seconds' => 5]);
        $this->actingAs($this->owner)->postJson("{$this->base}/resources", ['name' => 'database', 'type' => 'mysql', 'is_managed' => '1'])->assertSuccessful();
        $this->actingAs($this->owner)->postJson("{$this->base}/resources", ['name' => 'assets', 'type' => 'object_storage', 'variables' => "AWS_BUCKET=assets\nAWS_REGION=eu-west-1"])->assertSuccessful();
        $this->actingAs($this->owner)->putJson("{$this->base}/settings", $this->settings(['runtime_type' => 'php', 'deployment_strategy' => 'rolling']))->assertSuccessful();

        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertSuccessful();
        $payload = Build::query()->sole()->environment_payload ?? [];
        $this->assertSame(['STRIPE_SECRET' => 'sk_live_2'], $payload['variables']);
        $this->assertSame(['STRIPE_SECRET' => 'sk_live_2', 'VITE_APP_NAME' => 'Shop'], $payload['build_variables']);
        /** @var list<array<string, mixed>> $processes */
        $processes = $payload['processes'] ?? [];
        /** @var list<array<string, mixed>> $resources */
        $resources = $payload['resources'] ?? [];
        $this->assertSame(1, collect($processes)->firstWhere('name', 'scheduler')['replicas'] ?? null);
        $this->assertSame('rolling', $payload['runtime']['deployment_strategy'] ?? null);
        $this->assertSame('shop', collect($resources)->firstWhere('name', 'database')['configuration']['variables']['DB_DATABASE'] ?? null);
        $script = $this->scripts->started[0]['script'];
        $this->assertStringContainsString('buildpusher-shop-queue', $script);
        preg_match("#printf '%s' '([A-Za-z0-9+/=]+)' \\| base64 --decode > '/var/www/shop/\\.env'#", $script, $env);
        $this->assertStringContainsString('AWS_BUCKET="assets"', (string) base64_decode($env[1] ?? '', true));

        $this->actingAs($this->owner)->putJson("{$this->base}/variables", ['variables' => "# pasted\nAPP_NAME=\"My shop\"\nSTRIPE_SECRET=sk_live_3\n"])->assertSuccessful();
        $this->assertSame(['APP_NAME' => 'My shop', 'STRIPE_SECRET' => 'sk_live_3'], $this->production->variables()->orderBy('key')->get()->mapWithKeys(fn (EnvironmentVariable $variable) => [$variable->key => $variable->value])->all());
        $this->actingAs($this->owner)->putJson("{$this->base}/variables", ['variables' => 'not a variable'])->assertJsonValidationErrors('variables');

        $this->actingAs($this->owner)->putJson("{$this->base}/settings", $this->settings(['maximum_replicas' => 3]))->assertJsonValidationErrors('maximum_replicas');
        $this->onTier($this->project, 'deploy', 'free');
        $this->actingAs($this->owner)->postJson("{$this->base}/processes", ['name' => 'more', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5])->assertJsonValidationErrors('process');
    }

    /**
     * Locks and windows hold deploys and waiting pushes follow.
     */
    public function test_locks_and_windows_hold_deploys_and_waiting_pushes_follow(): void
    {
        $this->actingAs($this->owner)->putJson("{$this->base}/controls", ['locked' => '1', 'lock_reason' => 'Black Friday freeze'])->assertSuccessful();
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertJsonValidationErrors(['deploy' => 'Black Friday freeze']);

        $secret = 'push-secret';
        $this->repository->forceFill(['webhook_enabled' => true, 'webhook_secret' => $secret])->save();
        $body = json_encode(['ref' => 'refs/heads/main', 'after' => str_repeat('c', 40), 'head_commit' => ['message' => 'Queued'], 'commits' => [['modified' => ['app.php']]]], JSON_THROW_ON_ERROR);
        $this->call('POST', "/api/repositories/{$this->repository->id}/webhook", [], [], [], ['HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_GITHUB_DELIVERY' => 'd1', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret)], $body)
            ->assertStatus(202)->assertJson(['status' => 'pending']);
        $this->command('builds:release-pending')->expectsOutput('Started 0 waiting push deploys.');

        $this->actingAs($this->owner)->putJson("{$this->base}/controls", ['window' => '1', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'timezone' => 'Europe/Amsterdam'])->assertSuccessful();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-03 12:00', 'Europe/Amsterdam'));
        $this->assertNotNull($this->production->refresh()->deploymentBlockReason());
        $this->command('builds:release-pending')->expectsOutput('Started 0 waiting push deploys.');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-05 10:00', 'Europe/Amsterdam'));
        $this->command('builds:release-pending')->expectsOutput('Started 1 waiting push deploys.');
        $this->assertSame(str_repeat('c', 40), Build::query()->sole()->revision);
    }

    /**
     * Failed live deploys and failed observations roll back automatically.
     */
    public function test_failed_live_deploys_and_failed_observations_roll_back_automatically(): void
    {
        $this->actingAs($this->owner)->putJson("{$this->base}/settings", $this->settings(['automatic_rollback' => '1', 'post_deployment_observation_minutes' => 5]))->assertSuccessful();
        $good = Build::factory()->succeeded()->create(['repository_id' => $this->repository->id, 'environment_id' => $this->production->id]);

        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds");
        $bad = Build::query()->latest('id')->firstOrFail();
        foreach ([1, 9, 10] as $stage) {
            $this->post(ProvisioningCallbackUrl::buildStatus($bad), ['status' => $stage]);
        }
        $this->post(ProvisioningCallbackUrl::buildFailure($bad), ['message' => 'Health check failed', 'exit_code' => 22])->assertNoContent();
        $rollback = Build::query()->latest('id')->firstOrFail();
        $this->assertSame([$rollback->id, $good->id, Build::STATUS_SUCCEEDED], [$this->reload($bad)->automatic_rollback_build_id, $rollback->rolled_back_from_build_id, $rollback->status]);

        // A deploy that goes live is watched; a failing health check rolls back too.
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds");
        $watched = Build::query()->latest('id')->firstOrFail();
        $this->post(ProvisioningCallbackUrl::buildStatus($watched), ['status' => 15]);
        $this->assertSame('observing', $this->reload($watched)->observation_status);
        $site = new class
        {
            public int $status = 200;
        };
        Http::fake(fn () => Http::response('', $site->status));
        $this->command('builds:observe')->expectsOutput('Checked 1 deploys under observation; 0 failed.');
        $site->status = 503;
        $this->command('builds:observe')->expectsOutput('Checked 1 deploys under observation; 1 failed.');
        $this->assertSame('failed', $this->reload($watched)->observation_status);
        $this->assertNotNull($this->reload($watched)->automatic_rollback_build_id);
    }

    /**
     * Check that a watched deploy fails and rolls back when failed requests jump past the environment's limit, but
     * not while there are too few requests to judge or when errors were already as high before.
     *
     * @return void
     */
    public function test_a_jump_in_failed_requests_fails_the_deploy_and_rolls_back(): void
    {
        $this->actingAs($this->owner)->putJson("{$this->base}/settings", $this->settings(['automatic_rollback' => '1', 'post_deployment_observation_minutes' => 5, 'rollback_error_rate_percent' => 5]))->assertSuccessful();
        $this->assertSame(5, $this->reload($this->production)->rollback_error_rate_percent);
        Build::factory()->succeeded()->create(['repository_id' => $this->repository->id, 'environment_id' => $this->production->id]);
        Http::fake(fn () => Http::response('', 200));
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds");
        $watched = Build::query()->latest('id')->firstOrFail();
        $this->post(ProvisioningCallbackUrl::buildStatus($watched), ['status' => 15]);
        $this->travel(2)->minutes();

        // A few failures out of too few requests don't count.
        $this->requests(5, 500, now()->subMinute());
        $this->command('builds:observe')->expectsOutput('Checked 1 deploys under observation; 0 failed.');

        // Healthy before, failing after: past the 5% limit.
        $this->requests(40, 200, now()->subMinutes(3));
        $this->requests(20, 200, now()->subMinute());
        $this->requests(5, 500, now()->subSeconds(30));
        $this->command('builds:observe')->expectsOutput('Checked 1 deploys under observation; 1 failed.');
        $this->assertSame('failed', $this->reload($watched)->observation_status);
        $this->assertStringContainsString('33.3% of requests failed after the deploy (0% before it)', (string) $this->reload($watched)->observation_error);
        $this->assertNotNull($this->reload($watched)->automatic_rollback_build_id);
    }

    /**
     * Check a watched deploy is compared on latency and conversions too: slower requests past the limit fail it, the
     * next one fails on a conversion drop from the environment's Analytics site, and the build page shows the
     * before-and-after analysis.
     *
     * @return void
     */
    public function test_release_analysis_rolls_back_slower_or_worse_converting_releases(): void
    {
        $this->actingAs($this->owner)->putJson("{$this->base}/settings", $this->settings(['automatic_rollback' => '1', 'post_deployment_observation_minutes' => 5, 'rollback_latency_percent' => 50, 'rollback_conversion_drop_percent' => 20]))->assertSuccessful();
        $this->assertSame([50, 20], [$this->reload($this->production)->rollback_latency_percent, $this->reload($this->production)->rollback_conversion_drop_percent]);
        Build::factory()->succeeded()->create(['repository_id' => $this->repository->id, 'environment_id' => $this->production->id]);
        Http::fake(fn () => Http::response('', 200));
        $deploy = function (): Build {
            $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds");
            $build = Build::query()->latest('id')->firstOrFail();
            $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => 15]);

            return $build;
        };

        $slow = $deploy();
        $this->travel(2)->minutes();
        TelemetryEvent::factory()->count(30)->create(['environment_id' => $this->production->id, 'type' => 'request', 'status_code' => 200, 'severity' => 'info', 'duration_ms' => 100, 'occurred_at' => now()->subMinutes(3)]);
        TelemetryEvent::factory()->count(30)->create(['environment_id' => $this->production->id, 'type' => 'request', 'status_code' => 200, 'severity' => 'info', 'duration_ms' => 300, 'occurred_at' => now()->subMinute()]);
        $this->command('builds:observe')->expectsOutput('Checked 1 deploys under observation; 1 failed.');
        $this->assertStringContainsString('Requests took 300 ms on average after the deploy (100 ms before it), over 50% slower.', (string) $this->reload($slow)->observation_error);
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/deploy/builds/{$slow->id}")->assertOk()->assertJsonPath('build.observation.status', fn (?string $status): bool => $status !== null)
            ->assertJsonPath('build.observation.before', fn (array $before): bool => in_array(100, $before, false))->assertJsonPath('build.observation.after', fn (array $after): bool => in_array(300, $after, false));

        TelemetryEvent::query()->delete();
        $site = AnalyticsSite::factory()->for($this->project)->create(['environment_id' => $this->production->id]);
        $this->travel(10)->minutes();
        $worse = $deploy();
        $this->travel(2)->minutes();
        $visit = function (int $count, int $converted, \Illuminate\Support\Carbon $at) use ($site): void {
            foreach (range(1, $count) as $index) {
                (new AnalyticsVisit)->forceFill(['site_id' => $site->id, 'visit_key' => uniqid('v', true), 'started_at' => $at, 'last_seen_at' => $at, 'pageviews' => 1, 'conversion_count' => $index <= $converted ? 1 : 0])->save();
            }
        };
        $visit(60, 12, now()->subMinutes(3));
        $visit(60, 3, now()->subMinute());
        $this->command('builds:observe')->expectsOutput('Checked 1 deploys under observation; 1 failed.');
        $this->assertStringContainsString('The conversion rate fell to 5% after the deploy (20% before it)', (string) $this->reload($worse)->observation_error);
    }

    /**
     * Check an environment is cloned with its settings, workers, variables and recipes, secrets left out unless asked
     * for (and listed), schedules switched off, and nothing from another project.
     *
     * @return void
     */
    public function test_an_environment_is_cloned_from_another(): void
    {
        $this->production->forceFill(['deployment_strategy' => 'canary', 'post_deployment_observation_minutes' => 10, 'runtime_type' => 'node', 'maximum_replicas' => 4])->save();
        foreach ([['APP_NAME', 'Shop', false], ['STRIPE_SECRET', 'sk_live_1', true]] as [$key, $value, $secret]) {
            $variable = new EnvironmentVariable;
            $variable->forceFill(['environment_id' => $this->production->id, 'key' => $key, 'value' => $value, 'is_secret' => $secret, 'scope' => 'runtime', 'current_version' => 1])->save();
        }
        (new EnvironmentProcess)->forceFill(['environment_id' => $this->production->id, 'name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 2, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true])->save();
        (new DeploymentSchedule)->forceFill(['environment_id' => $this->production->id, 'name' => 'Nightly', 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC', 'is_enabled' => true])->save();
        $clone = "/api/app/projects/{$this->project->id}/environments/clone";

        $this->actingAs($this->owner)->postJson($clone, ['source_id' => $this->production->id, 'name' => 'Staging', 'kind' => 'staging'])
            ->assertSuccessful()->assertSuccessful();
        $staging = $this->project->environments()->where('slug', 'staging')->firstOrFail();
        $this->assertSame(['canary', 10, 'node', 4], [$staging->deployment_strategy, $staging->post_deployment_observation_minutes, $staging->runtime_type, $staging->maximum_replicas]);
        $this->assertSame(['APP_NAME' => 'Shop'], $staging->variables()->pluck('value', 'key')->all());
        $this->assertSame(['queue'], EnvironmentProcess::query()->where('environment_id', $staging->id)->pluck('name')->all());
        $this->assertFalse(DeploymentSchedule::query()->where('environment_id', $staging->id)->sole()->is_enabled, 'Schedules come across switched off.');

        $this->actingAs($this->owner)->postJson($clone, ['source_id' => $this->production->id, 'name' => 'QA', 'kind' => 'staging', 'copy_secrets' => '1'])->assertSuccessful();
        $this->assertSame('sk_live_1', $this->project->environments()->where('slug', 'qa')->firstOrFail()->variables()->where('key', 'STRIPE_SECRET')->value('value'));
        $this->actingAs($this->owner)->postJson($clone, ['source_id' => $this->production->id, 'name' => 'Staging', 'kind' => 'staging'])->assertJsonValidationErrors('name');
        $foreign = Project::factory()->create()->environments()->firstOrFail();
        $this->actingAs($this->owner)->postJson($clone, ['source_id' => $foreign->id, 'name' => 'Stolen', 'kind' => 'staging'])->assertNotFound();
    }

    /**
     * Check a Docker Compose environment builds the stack and runs it as one project per website, publishing only the
     * chosen web service to Caddy with the website's .env, and that the deploy script is valid bash.
     *
     * @return void
     */
    public function test_a_docker_compose_app_deploys_as_a_stack(): void
    {
        $this->actingAs($this->owner)->putJson("{$this->base}/settings", $this->settings(['runtime_type' => 'compose', 'dockerfile_path' => 'deploy/compose.yaml', 'compose_service' => 'app', 'container_port' => 8000]))->assertSuccessful();
        $this->assertSame(['compose', 'app'], [$this->reload($this->production)->runtime_type, $this->reload($this->production)->compose_service]);
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertSuccessful();
        $script = $this->scripts->started[0]['script'];

        $this->assertStringContainsString("docker compose --project-name 'buildpusher-shop' --file 'deploy/compose.yaml' build --pull", $script);
        $this->assertStringContainsString('up --detach --remove-orphans', $script);
        $this->assertSame(1, preg_match("#printf '%s' '([A-Za-z0-9+/=]+)' \\| base64 --decode > '/var/www/shop/shared/compose.override.yaml'#", $script, $override));
        $yaml = (string) base64_decode($override[1] ?? '');
        $this->assertStringContainsString("  app:\n    env_file:\n      - /var/www/shop/.env", $yaml);
        $this->assertMatchesRegularExpression('#"127\.0\.0\.1:\d+:8000"#', $yaml);
        exec('bash -n <<\'SCRIPT\''."\n".$script."\nSCRIPT\n".' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    /**
     * Record requests for production at a time.
     *
     * @param  int  $count
     * @param  int  $status
     * @param  \Illuminate\Support\Carbon  $at
     * @return void
     */
    private function requests(int $count, int $status, \Illuminate\Support\Carbon $at): void
    {
        TelemetryEvent::factory()->count($count)->create(['environment_id' => $this->production->id, 'type' => 'request', 'status_code' => $status, 'severity' => $status >= 500 ? 'error' : 'info', 'occurred_at' => $at]);
    }

    /**
     * Viewers see settings without changing them.
     */
    public function test_viewers_see_settings_without_changing_them(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->postJson("{$this->base}/variables", ['key' => 'X', 'value' => 'y', 'scope' => 'runtime'])->assertForbidden();
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/deploy/environments")->assertOk()->assertSee('Production');
        $this->actingAs($this->owner)->getJson('/api/app/projects/'.$this->project->id.'/deploy/environments/'.Environment::factory()->create()->id)->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return ['deployment_strategy' => 'blue_green', 'rolling_pause_seconds' => 2, 'runtime_type' => 'php', 'minimum_replicas' => 1, 'maximum_replicas' => 1, 'desired_replicas' => 1, ...$overrides];
    }
}
