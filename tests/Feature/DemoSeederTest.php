<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\ServerLogSnapshot;
use App\Models\SignInEvent;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_an_idempotent_full_feature_workspace(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', [
            '--class' => DemoSeeder::class,
            '--force' => true,
        ]), Artisan::output());

        $user = User::query()->where('email', DemoSeeder::EMAIL)->sole();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->password_set_at);
        $this->assertSame('demo-github-identity', $user->github_id);
        $this->assertSame('github', $user->auth_type);
        $this->assertSame(3, $user->signIns()->count());
        $this->assertEqualsCanonicalizing(
            [SignInEvent::METHOD_PASSWORD, 'github', 'gitlab'],
            $user->signIns()->pluck('method')->all(),
        );
        $this->assertDatabaseHas('sessions', [
            'id' => DemoAccountSeeder::SESSION_ID,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
        ]);
        $this->assertSame(5, $user->providers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertEqualsCanonicalizing([
            Provider::TYPE_DIGITALOCEAN,
            Provider::TYPE_GITHUB,
            Provider::TYPE_GITLAB,
            Provider::TYPE_BITBUCKET,
        ], $user->providers()->distinct()->pluck('provider')->all());
        $this->assertEqualsCanonicalizing([
            Provider::CONNECTION_HEALTHY,
            Provider::CONNECTION_FAILED,
            null,
        ], $user->providers()->distinct()->pluck('connection_status')->all());
        $this->assertSame(0, $user->providers()->where('connection_monitoring_enabled', true)->count());
        $spareProvider = $user->providers()->where('name', DemoSeeder::PREFIX.'Spare GitHub')->sole();
        $this->assertSame(Provider::CONNECTION_UNCHECKED, $spareProvider->connectionHealth());
        $this->assertFalse($spareProvider->hasAttachedResources());
        $this->assertEqualsCanonicalizing(
            Provider::CONNECTION_CHECK_INTERVALS,
            $user->providers()->distinct()->pluck('connection_check_interval_minutes')->all(),
        );
        $this->assertEqualsCanonicalizing(
            Provider::CONNECTION_FAILURE_THRESHOLDS,
            $user->providers()->distinct()->pluck('connection_failure_threshold')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [0, 3],
            $user->providers()->distinct()->pluck('connection_failure_count')->all(),
        );
        $this->assertSame(6, ProviderConnectionCheck::query()
            ->whereHas('provider', fn ($query) => $query->where('user_id', $user->id))
            ->count());
        $this->assertEqualsCanonicalizing(
            [ProviderConnectionCheck::SOURCE_AUTOMATIC, ProviderConnectionCheck::SOURCE_MANUAL],
            ProviderConnectionCheck::query()
                ->whereHas('provider', fn ($query) => $query->where('user_id', $user->id))
                ->distinct()
                ->pluck('source')
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            [false, true],
            ProviderConnectionCheck::query()
                ->whereHas('provider', fn ($query) => $query->where('user_id', $user->id))
                ->distinct()
                ->pluck('successful')
                ->map(fn ($value): bool => (bool) $value)
                ->all(),
        );
        $this->assertSame(3, $user->recipes()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $unusedRecipe = $user->recipes()->where('name', DemoSeeder::PREFIX.'Optimize PHP runtime')->sole();
        $this->assertFalse($unusedRecipe->servers()->exists());
        $this->assertSame(5, $user->servers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertSame(
            DemoSeeder::PREFIX.'Primary production',
            $user->servers()->where('name', DemoSeeder::PREFIX.'Production application')->sole()->label,
        );
        $this->assertEqualsCanonicalizing([
            Server::STATUS_QUEUED,
            Server::STATUS_WAITING_FOR_IP,
            Server::STATUS_PROVISIONING,
            Server::STATUS_ACTIVE,
            Server::STATUS_FAILED,
        ], $user->servers()->pluck('provisioning_status')->all());
        $this->assertSame(3, $user->servers()->whereIn('provisioning_status', Server::ACTIVE_PROVISIONING_STATUSES)->count());
        $this->assertSame(5, $user->websites()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertSame(6, WebsiteHealthCheck::query()
            ->whereHas('website', fn ($query) => $query->where('user_id', $user->id))
            ->count());
        $this->assertEqualsCanonicalizing(
            [WebsiteHealthCheck::SOURCE_AUTOMATIC, WebsiteHealthCheck::SOURCE_MANUAL],
            WebsiteHealthCheck::query()
                ->whereHas('website', fn ($query) => $query->where('user_id', $user->id))
                ->distinct()
                ->pluck('source')
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            [false, true],
            WebsiteHealthCheck::query()
                ->whereHas('website', fn ($query) => $query->where('user_id', $user->id))
                ->distinct()
                ->pluck('successful')
                ->map(fn ($value): bool => (bool) $value)
                ->all(),
        );
        $this->assertEqualsCanonicalizing([
            Website::STATUS_QUEUED,
            Website::STATUS_PROVISIONING,
            Website::STATUS_ACTIVE,
            Website::STATUS_FAILED,
        ], $user->websites()->distinct()->pluck('provisioning_status')->all());
        $this->assertSame(2, $user->websites()->whereIn('provisioning_status', Website::ACTIVE_PROVISIONING_STATUSES)->count());
        $this->assertEqualsCanonicalizing([
            Website::HEALTH_HEALTHY,
            Website::HEALTH_UNHEALTHY,
            Website::HEALTH_UNKNOWN,
        ], $user->websites()->distinct()->pluck('health_status')->all());
        $this->assertSame(0, $user->websites()->where('health_monitoring_enabled', true)->count());
        $this->assertEqualsCanonicalizing(
            Website::HEALTH_CHECK_INTERVALS,
            $user->websites()->pluck('health_check_interval_minutes')->all(),
        );
        $this->assertEqualsCanonicalizing(
            Website::HEALTH_FAILURE_THRESHOLDS,
            $user->websites()->pluck('health_failure_threshold')->all(),
        );
        $this->assertSame(4, $user->repositories()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $neverDeployedRepository = $user->repositories()
            ->where('name', DemoSeeder::PREFIX.'Documentation repository')
            ->sole();
        $this->assertFalse($neverDeployedRepository->webhook_enabled);
        $this->assertFalse($neverDeployedRepository->builds()->exists());
        $this->assertSame(6, $user->builds()->count());
        $this->assertSame(2, $user->builds()->whereNotNull('operator_note')->count());
        $this->assertEqualsCanonicalizing(Build::TERMINAL_STATUSES, $user->builds()->distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing([
            Build::TRIGGER_MANUAL,
            Build::TRIGGER_WEBHOOK,
            Build::TRIGGER_REDEPLOY,
        ], $user->builds()->distinct()->pluck('trigger_source')->all());
        $this->assertEqualsCanonicalizing(
            RepositoryWebhookDelivery::STATUSES,
            RepositoryWebhookDelivery::query()
                ->whereHas('repository', fn ($query) => $query->where('user_id', $user->id))
                ->pluck('status')
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            ServerCommandExecution::STATUSES,
            ServerCommandExecution::query()->where('user_id', $user->id)->distinct()->pluck('status')->all(),
        );
        $this->assertSame(2, ServerCommandExecution::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ServerCommandExecution::ACTIVE_STATUSES)
            ->count());
        $this->assertEqualsCanonicalizing(
            ServerLogSnapshot::STATUSES,
            ServerLogSnapshot::query()
                ->whereHas('server', fn ($query) => $query->where('user_id', $user->id))
                ->distinct()
                ->pluck('status')
                ->all(),
        );
        $demoRerun = ServerCommandExecution::query()
            ->where('user_id', $user->id)
            ->whereNotNull('rerun_from_execution_id')
            ->sole();
        $this->assertSame($demoRerun->command, $demoRerun->rerunFrom->command);
        $this->assertEqualsCanonicalizing(
            ['deployment', 'website', 'server', 'command', 'provider', 'account', 'general'],
            $user->events()->pluck('category')->all(),
        );
        $this->assertSame(6, $user->notifications()->count());
        $this->assertSame(4, $user->unreadNotifications()->count());
        $this->assertSame(2, $user->readNotifications()->count());
        $providerFailure = $user->notifications()
            ->where('data->category', 'provider')
            ->where('data->status', 'failed')
            ->sole();
        $providerRecovery = $user->notifications()
            ->where('data->category', 'provider')
            ->where('data->status', 'healthy')
            ->sole();
        $this->assertNotNull($providerFailure->read_at);
        $this->assertNull($providerRecovery->read_at);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data->category' => 'account',
            'data->status' => 'info',
            'data->demo' => true,
        ]);
        $this->assertDatabaseCount('jobs', 0);

        $counts = $this->demoCounts($user);
        $user->providers()->create([
            'name' => 'Personal provider',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'personal-token',
            'description' => 'Must not be changed by demo reseeding.',
        ]);
        $this->assertSame(0, Artisan::call('db:seed', [
            '--class' => DemoSeeder::class,
            '--force' => true,
        ]), Artisan::output());
        $user->refresh();
        $this->assertSame($counts, $this->demoCounts($user));
        $this->assertSame(1, $user->providers()->where('name', 'Personal provider')->count());
    }

    public function test_demo_secrets_are_encrypted_and_account_can_sign_in(): void
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $user = User::query()->where('email', DemoSeeder::EMAIL)->sole();
        $provider = $user->providers()->where('name', DemoSeeder::PREFIX.'GitHub')->sole();
        $server = $user->servers()->where('name', DemoSeeder::PREFIX.'Production application')->sole();
        $website = $user->websites()->where('deployment_slug', 'demo-storefront')->sole();
        $repository = $user->repositories()->where('name', DemoSeeder::PREFIX.'Storefront repository')->sole();
        $recipe = $user->recipes()->where('name', DemoSeeder::PREFIX.'Install image tools')->sole();
        $unusedRecipe = $user->recipes()->where('name', DemoSeeder::PREFIX.'Optimize PHP runtime')->sole();
        $execution = ServerCommandExecution::query()
            ->where('user_id', $user->id)
            ->where('status', ServerCommandExecution::STATUS_SUCCEEDED)
            ->whereNull('rerun_from_execution_id')
            ->sole();

        $this->assertNotSame($provider->token, DB::table('providers')->where('id', $provider->id)->value('token'));
        $this->assertNotSame($server->ssh_private_key, DB::table('servers')->where('id', $server->id)->value('ssh_private_key'));
        $this->assertNotSame($website->environment, DB::table('websites')->where('id', $website->id)->value('environment'));
        $this->assertNotSame($repository->webhook_secret, DB::table('repositories')->where('id', $repository->id)->value('webhook_secret'));
        $this->assertNotSame($recipe->script, DB::table('recipes')->where('id', $recipe->id)->value('script'));
        $this->assertNotSame($unusedRecipe->script, DB::table('recipes')->where('id', $unusedRecipe->id)->value('script'));
        $this->assertNotSame($execution->command, DB::table('server_command_executions')->where('id', $execution->id)->value('command'));

        $this->post(route('login'), [
            'email' => DemoSeeder::EMAIL,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_seeded_feature_pages_render_for_the_demo_owner(): void
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $user = User::query()->where('email', DemoSeeder::EMAIL)->sole();
        $provider = $user->providers()->where('name', DemoSeeder::PREFIX.'GitHub')->sole();
        $failedProvider = $user->providers()->where('name', DemoSeeder::PREFIX.'GitLab')->sole();
        $emptyHistoryProvider = $user->providers()->where('name', DemoSeeder::PREFIX.'Bitbucket')->sole();
        $spareProvider = $user->providers()->where('name', DemoSeeder::PREFIX.'Spare GitHub')->sole();
        $server = $user->servers()->where('name', DemoSeeder::PREFIX.'Production application')->sole();
        $queuedServer = $user->servers()->where('name', DemoSeeder::PREFIX.'Queued application')->sole();
        $provisioningServer = $user->servers()->where('name', DemoSeeder::PREFIX.'Provisioning worker')->sole();
        $website = $user->websites()->where('deployment_slug', 'demo-storefront')->sole();
        $unhealthyWebsite = $user->websites()->where('deployment_slug', 'demo-status')->sole();
        $repository = $user->repositories()->where('name', DemoSeeder::PREFIX.'Storefront repository')->sole();
        $neverDeployedRepository = $user->repositories()->where('name', DemoSeeder::PREFIX.'Documentation repository')->sole();
        $unusedRecipe = $user->recipes()->where('name', DemoSeeder::PREFIX.'Optimize PHP runtime')->sole();
        $build = $repository->builds()->latest()->firstOrFail();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Infrastructure provisioning')
            ->assertSee('5 resources are being prepared')
            ->assertSee(DemoSeeder::PREFIX.'Waiting for IP')
            ->assertSee(DemoSeeder::PREFIX.'Provisioning website')
            ->assertSee('Active server commands')
            ->assertSee('2 commands are active')
            ->assertSee('Running');
        $this->actingAs($user)->get(route('providers.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 5,
                'in_use' => 4,
                'unused' => 1,
                'healthy' => 2,
                'failed' => 1,
                'unchecked' => 2,
            ])
            ->assertSee('Matching providers')
            ->assertSee('Unchecked connections');
        $this->actingAs($user)->get(route('providers.index', ['usage' => 'unused']))
            ->assertSuccessful()
            ->assertViewHas('providers', fn ($providers): bool => $providers->count() === 1
                && $providers->sole()->id === $spareProvider->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'in_use' => 0,
                'unused' => 1,
                'healthy' => 0,
                'failed' => 0,
                'unchecked' => 1,
            ])
            ->assertSee(DemoSeeder::PREFIX.'Spare GitHub')
            ->assertDontSee(route('providers.show', $provider));
        $this->actingAs($user)->get(route('recipes.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 3
                && $metrics['in_use'] === 2
                && $metrics['unused'] === 1
                && $metrics['assignments'] === 2
                && $metrics['servers'] === 1
                && $metrics['latest_at'] !== null)
            ->assertSee('Matching recipes')
            ->assertSee('Covered servers');
        $this->actingAs($user)->get(route('recipes.index', ['usage' => 'unused']))
            ->assertSuccessful()
            ->assertViewHas('recipes', fn ($recipes): bool => $recipes->count() === 1
                && $recipes->sole()->id === $unusedRecipe->id)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['in_use'] === 0
                && $metrics['unused'] === 1
                && $metrics['assignments'] === 0
                && $metrics['servers'] === 0
                && $metrics['latest_at'] !== null)
            ->assertSee(DemoSeeder::PREFIX.'Optimize PHP runtime')
            ->assertDontSee(DemoSeeder::PREFIX.'Install image tools');
        $this->actingAs($user)->get(route('servers.commands.index', $server))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 6,
                'active' => 2,
                'succeeded' => 2,
                'failed' => 1,
                'canceled' => 1,
                'output' => 4,
            ])
            ->assertSee('Matching commands')
            ->assertSee('Output retained');
        $this->actingAs($user)->get(route('servers.commands.index', [
            $server,
            'status' => ServerCommandExecution::STATUS_FAILED,
        ]))
            ->assertSuccessful()
            ->assertViewHas('executions', fn ($executions): bool => $executions->count() === 1
                && $executions->sole()->status === ServerCommandExecution::STATUS_FAILED)
            ->assertViewHas('metrics', [
                'total' => 1,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 1,
                'canceled' => 0,
                'output' => 1,
            ]);
        $this->actingAs($user)->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertViewHas('connectionMetrics', [
                'total' => 2,
                'successful' => 1,
                'success_rate' => 50,
                'median_successful_duration_ms' => 85,
                'failure_streak' => 0,
            ])
            ->assertSee('Recent connection checks')
            ->assertSee('every 6 hours')
            ->assertSee('after 2 consecutive failures')
            ->assertSee('Observed connection success')
            ->assertSee('50%')
            ->assertSee('Median successful response')
            ->assertSee('Manual')
            ->assertSee('Automatic')
            ->assertSee('HTTP 401')
            ->assertSee('85 ms')
            ->assertSee('Demo GitHub credential was rejected before recovery.')
            ->assertSee(route('providers.connection-checks.index', $provider))
            ->assertSee(route('providers.connection-checks.export', $provider));
        $this->actingAs($user)->get(route('providers.show', $failedProvider))
            ->assertSuccessful()
            ->assertViewHas('connectionMetrics', [
                'total' => 3,
                'successful' => 0,
                'success_rate' => 0,
                'median_successful_duration_ms' => null,
                'failure_streak' => 3,
            ])
            ->assertSee('0%')
            ->assertSee('Not recorded')
            ->assertSee('after 3 consecutive failures')
            ->assertSee('3 failures recorded')
            ->assertSee('3 consecutive failed checks');
        $this->actingAs($user)->get(route('providers.show', $emptyHistoryProvider))
            ->assertSuccessful()
            ->assertViewHas('connectionMetrics', [
                'total' => 0,
                'successful' => 0,
                'success_rate' => null,
                'median_successful_duration_ms' => null,
                'failure_streak' => 0,
            ])
            ->assertSee('Not available')
            ->assertSee('No connection checks have been recorded yet.');
        $this->actingAs($user)->get(route('providers.connection-checks.index', $emptyHistoryProvider))
            ->assertSuccessful()
            ->assertSee('0 matching retained checks')
            ->assertSee('No connection checks have been recorded yet.');
        $providerHistoryExport = $this->actingAs($user)
            ->get(route('providers.connection-checks.export', $provider));
        $providerHistoryExport->assertSuccessful();
        $this->assertStringContainsString(
            'Demo GitHub credential was rejected before recovery.',
            $providerHistoryExport->streamedContent(),
        );
        $this->actingAs($user)->get(route('providers.connection-checks.index', [
            $provider,
            'result' => 'failed',
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
        ]))
            ->assertSuccessful()
            ->assertViewHas('connectionChecks', fn ($checks): bool => $checks->total() === 1)
            ->assertSee('1 matching retained check')
            ->assertSee('Demo GitHub credential was rejected before recovery.')
            ->assertSee(route('providers.connection-checks.export', [
                $provider,
                'result' => 'failed',
                'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            ]));
        $filteredProviderHistoryExport = $this->actingAs($user)->get(route('providers.connection-checks.export', [
            $provider,
            'result' => 'failed',
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
        ]));
        $filteredProviderHistoryExport->assertSuccessful();
        $this->assertSame(1, substr_count(
            $filteredProviderHistoryExport->streamedContent(),
            'https://api.github.com/user',
        ));
        $this->actingAs($user)->get(route('servers.show', $queuedServer))
            ->assertSuccessful()
            ->assertSee('wire:poll.5s', false)
            ->assertSee('Log refresh queued.');
        $this->actingAs($user)->get(route('servers.show', $provisioningServer))
            ->assertSuccessful()
            ->assertSee('wire:poll.5s', false)
            ->assertSee('Refreshing this log snapshot')
            ->assertSee('Demo provisioning is still running');
        $this->actingAs($user)->get(route('search.index', ['q' => 'Demo']))
            ->assertSuccessful()
            ->assertSee(DemoSeeder::PREFIX.'Storefront')
            ->assertSee(DemoSeeder::PREFIX.'Primary production')
            ->assertSee(DemoSeeder::PREFIX.'GitHub')
            ->assertSee(DemoSeeder::PREFIX.'Install image tools');
        $this->actingAs($user)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertViewHas('deploymentMetrics', [
                'total' => 3,
                'succeeded' => 2,
                'failed' => 1,
                'success_rate' => 67,
                'median_duration_seconds' => 180,
                'duration_sample_size' => 3,
            ])
            ->assertViewHas('deliveryMetrics', [
                'total' => 2,
                'queued' => 1,
                'pending' => 0,
                'unavailable' => 0,
                'superseded' => 1,
                'received' => 0,
            ])
            ->assertSee('Deployment insights')
            ->assertSee('Matching deliveries')
            ->assertSee('67%')
            ->assertSee('3m')
            ->assertSee(route('builds.index', ['repository_id' => $repository->id]));
        $this->actingAs($user)->get(route('repositories.show', [
            $repository,
            'delivery_status' => RepositoryWebhookDelivery::STATUS_QUEUED,
        ]))
            ->assertSuccessful()
            ->assertViewHas('deliveryMetrics', [
                'total' => 1,
                'queued' => 1,
                'pending' => 0,
                'unavailable' => 0,
                'superseded' => 0,
                'received' => 0,
            ]);
        $this->actingAs($user)->get(route('repositories.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 4,
                'never_deployed' => 1,
                'active' => 0,
                'succeeded' => 1,
                'failed' => 1,
                'webhooks' => 3,
            ])
            ->assertSee('Matching repositories')
            ->assertSee('Push webhooks');
        $this->actingAs($user)->get(route('repositories.index', ['status' => 'none']))
            ->assertSuccessful()
            ->assertViewHas('repositories', fn ($repositories): bool => $repositories->count() === 1
                && $repositories->sole()->id === $neverDeployedRepository->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'never_deployed' => 1,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'webhooks' => 0,
            ])
            ->assertSee(DemoSeeder::PREFIX.'Documentation repository')
            ->assertSee('Never deployed');
        $this->actingAs($user)->get(route('builds.index', ['status' => Build::STATUS_SUCCEEDED]))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 3
                && $metrics['active'] === 0
                && $metrics['succeeded'] === 3
                && $metrics['failed'] === 0
                && $metrics['success_rate'] === 100
                && $metrics['latest_at'] !== null)
            ->assertSee('Matching deployments')
            ->assertSee('Observed success')
            ->assertSee('100%');
        $this->actingAs($user)->get(route('builds.index', ['status' => Build::STATUS_RUNNING]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'success_rate' => null,
                'latest_at' => null,
            ])
            ->assertSee('No matching deployment recorded.')
            ->assertSee('No builds match these filters');
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertViewHas('healthMetrics', [
                'total' => 3,
                'successful' => 2,
                'success_rate' => 67,
                'median_healthy_duration_ms' => 108,
                'failure_streak' => 0,
            ])
            ->assertSee('Recent health checks')
            ->assertSee('every 5 minutes')
            ->assertSee('After 3 consecutive failures')
            ->assertSee('Observed check success')
            ->assertSee('67%')
            ->assertSee('108 ms')
            ->assertSee('0 consecutive failed checks')
            ->assertSee('not an SLA uptime calculation.')
            ->assertSee(route('websites.health-checks.index', $website))
            ->assertSee('Manual')
            ->assertSee('Automatic')
            ->assertSee('HTTP 200')
            ->assertSee('95 ms')
            ->assertSee('Demo transient HTTP 503 before recovery.')
            ->assertSee(route('websites.health-checks.export', $website));
        $this->actingAs($user)->get(route('websites.show', $unhealthyWebsite))
            ->assertSuccessful()
            ->assertViewHas('healthMetrics', [
                'total' => 3,
                'successful' => 0,
                'success_rate' => 0,
                'median_healthy_duration_ms' => null,
                'failure_streak' => 3,
            ])
            ->assertSee('0%')
            ->assertSee('Not recorded')
            ->assertSee('3 consecutive failed checks');
        $this->actingAs($user)->get(route('websites.health-checks.index', $website))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 3
                && $metrics['healthy'] === 2
                && $metrics['failed'] === 1
                && $metrics['success_rate'] === 67
                && $metrics['median_healthy_duration_ms'] === 108
                && $metrics['latest_at'] !== null)
            ->assertSee('67%')
            ->assertSee('108 ms');
        $this->actingAs($user)->get(route('websites.health-checks.index', [
            $website,
            'result' => 'failed',
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
        ]))
            ->assertSuccessful()
            ->assertViewHas('healthChecks', fn ($checks): bool => $checks->total() === 1)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['healthy'] === 0
                && $metrics['failed'] === 1
                && $metrics['success_rate'] === 0
                && $metrics['median_healthy_duration_ms'] === null
                && $metrics['latest_at'] !== null)
            ->assertSee('Matching checks')
            ->assertSee('1 matching retained check')
            ->assertSee('Demo transient HTTP 503 before recovery.')
            ->assertSee(route('websites.health-checks.export', [
                $website,
                'result' => 'failed',
                'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            ]));
        $healthExport = $this->actingAs($user)->get(route('websites.health-checks.export', $website));
        $healthExport->assertSuccessful();
        $this->assertStringContainsString('Demo transient HTTP 503 before recovery.', $healthExport->streamedContent());
        config([
            'session.driver' => 'database',
            'session.encrypt' => true,
        ]);
        $this->app['session']->forgetDrivers();
        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Recent security activity')
            ->assertSee('Recent sign-ins')
            ->assertSee('Chrome on macOS')
            ->assertSee('Safari on iPhone')
            ->assertSee('Firefox on Windows')
            ->assertSee('192.0.2.11')
            ->assertSee(route('account.sign-ins.index'))
            ->assertSee(route('account.sign-ins.export'))
            ->assertSee(route('account.sign-ins.destroy'))
            ->assertSee('Clear history')
            ->assertSee('Demo: account security settings were reviewed.')
            ->assertSee(route('activity.index', ['category' => 'account']))
            ->assertSee('Browser sessions')
            ->assertSee('Chrome on macOS')
            ->assertSee('192.0.2.10')
            ->assertSee(route('account.sessions.destroy', DemoAccountSeeder::SESSION_ID))
            ->assertSee(route('account.sessions.revoke'))
            ->assertSee('GitHub')
            ->assertSee('name="social_provider" value="github"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee(route('account.social.destroy', 'github'));
        $this->actingAs($user)->get(route('account.sign-ins.index', ['method' => 'github']))
            ->assertSuccessful()
            ->assertViewHas('signIns', fn ($signIns): bool => $signIns->total() === 1)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['password'] === 0
                && $metrics['social'] === 1
                && $metrics['known_ips'] === 1
                && $metrics['latest_at'] !== null)
            ->assertSee('1 matching sign-in')
            ->assertSee('Matching sign-ins')
            ->assertSee('Known IP addresses')
            ->assertSee('Safari on iPhone')
            ->assertSee('198.51.100.21')
            ->assertDontSee('Chrome on macOS')
            ->assertSee(route('account.sign-ins.export', ['method' => 'github']));
        $this->actingAs($user)->get(route('account.sign-ins.index', ['method' => 'bitbucket']))
            ->assertSuccessful()
            ->assertSee('0 matching sign-ins')
            ->assertSee('No sign-ins match these filters.');
        $filteredSignInExport = $this->actingAs($user)
            ->get(route('account.sign-ins.export', ['method' => 'github']));
        $filteredSignInExport->assertSuccessful();
        $filteredSignInContent = $filteredSignInExport->streamedContent();
        $this->assertSame(1, substr_count($filteredSignInContent, 'Safari on iPhone'));
        $this->assertStringNotContainsString('Chrome on macOS', $filteredSignInContent);
        $this->actingAs($user)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('[Demo] Approved rollback for incident DEMO-1042 after the checkout failure.')
            ->assertSee(route('builds.note.update', $build))
            ->assertSee('Duration')
            ->assertSee('3m')
            ->assertSee('Previous deployment')
            ->assertSee(route('builds.show', $build->previousInRepository()))
            ->assertSee(route('builds.compare', ['build' => $build, 'baseline' => $build->previousInRepository()]))
            ->assertSee('This is the latest recorded deployment for this repository.')
            ->assertSee('Save note');
        $this->actingAs($user)->get(route('builds.compare', [
            'build' => $build,
            'baseline' => $build->previousInRepository(),
        ]))
            ->assertSuccessful()
            ->assertSee('2m slower')
            ->assertSee('Demo storefront checkout failure')
            ->assertSee('[Demo] Approved rollback for incident DEMO-1042 after the checkout failure.');
        $this->actingAs($user)->get(route('notifications.index', ['status' => 'healthy']))
            ->assertSuccessful()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 1)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['unread'] === 1
                && $metrics['failed'] === 0
                && $metrics['healthy'] === 1
                && $metrics['info'] === 0
                && $metrics['latest_at'] !== null)
            ->assertSee('Demo provider connection recovered')
            ->assertSee('Matching alerts')
            ->assertSee('Recoveries')
            ->assertDontSee('Demo provider connection failed')
            ->assertSee(route('notifications.export', ['status' => 'healthy']));
        $this->actingAs($user)->get(route('notifications.index', ['status' => 'info']))
            ->assertSuccessful()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 1)
            ->assertSee('Demo account security changed');
        $notificationExport = $this->actingAs($user)
            ->get(route('notifications.export', ['status' => 'healthy']));
        $notificationExport->assertSuccessful();
        $notificationContent = $notificationExport->streamedContent();
        $this->assertStringContainsString('Demo provider connection recovered', $notificationContent);
        $this->assertStringNotContainsString('Demo provider connection failed', $notificationContent);
        $this->actingAs($user)->get(route('activity.index', ['search' => 'Demo:']))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 7
                && $metrics['deployments'] === 1
                && $metrics['infrastructure'] === 3
                && $metrics['commands'] === 1
                && $metrics['account'] === 1
                && $metrics['latest_at'] !== null)
            ->assertSee('Matching events')
            ->assertSee('Infrastructure')
            ->assertSee('Demo: account security settings were reviewed.');
        $this->actingAs($user)->get(route('activity.index', ['search' => 'Demo: no matching activity']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'deployments' => 0,
                'infrastructure' => 0,
                'commands' => 0,
                'account' => 0,
                'latest_at' => null,
            ])
            ->assertSee('No matching event recorded.')
            ->assertSee('No activity matches these filters');
        $this->actingAs($user)->get(route('servers.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 5
                && $metrics['ready'] === 1
                && $metrics['provisioning'] === 3
                && $metrics['failed'] === 1
                && $metrics['websites'] === 5
                && $metrics['latest_at'] !== null)
            ->assertSee('Matching servers')
            ->assertSee('Hosted websites');
        $this->actingAs($user)->get(route('servers.index', ['search' => 'Demo missing server']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'ready' => 0,
                'provisioning' => 0,
                'failed' => 0,
                'websites' => 0,
                'latest_at' => null,
            ])
            ->assertSee('No matching server recorded.')
            ->assertSee('No servers match these filters');
        $this->actingAs($user)->get(route('websites.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 5,
                'active' => 2,
                'provisioning' => 2,
                'failed' => 1,
                'unhealthy' => 1,
                'attention' => 2,
            ])
            ->assertSee('Matching websites')
            ->assertSee('Needs attention');
        $this->actingAs($user)->get(route('websites.index', ['search' => 'Demo missing website']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'provisioning' => 0,
                'failed' => 0,
                'unhealthy' => 0,
                'attention' => 0,
            ])
            ->assertSee('No websites match these filters');

        foreach ([
            route('dashboard'),
            route('providers.index'),
            route('providers.show', $provider),
            route('recipes.index'),
            route('servers.index'),
            route('servers.show', $server),
            route('servers.edit', $server),
            route('websites.index'),
            route('websites.show', $website),
            route('repositories.index'),
            route('repositories.show', $repository),
            route('builds.index'),
            route('builds.show', $build),
            route('activity.index'),
            route('notifications.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertSuccessful();
        }
    }

    public function test_default_database_seeder_loads_demo_data_in_testing(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--force' => true]), Artisan::output());
        $this->assertDatabaseHas('users', ['email' => DemoSeeder::EMAIL]);
    }

    /** @return array<string, int> */
    private function demoCounts(User $user): array
    {
        return [
            'providers' => $user->providers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'provider_checks' => ProviderConnectionCheck::query()
                ->whereHas('provider', fn ($query) => $query->where('user_id', $user->id))
                ->count(),
            'recipes' => $user->recipes()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'servers' => $user->servers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'websites' => $user->websites()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'health_checks' => WebsiteHealthCheck::query()
                ->whereHas('website', fn ($query) => $query->where('user_id', $user->id))
                ->count(),
            'repositories' => $user->repositories()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'builds' => $user->builds()->count(),
            'deliveries' => RepositoryWebhookDelivery::query()
                ->whereHas('repository', fn ($query) => $query->where('user_id', $user->id))
                ->count(),
            'commands' => ServerCommandExecution::query()->where('user_id', $user->id)->count(),
            'server_logs' => ServerLogSnapshot::query()
                ->whereHas('server', fn ($query) => $query->where('user_id', $user->id))
                ->count(),
            'events' => $user->events()->where('event', 'like', 'Demo:%')->count(),
            'notifications' => $user->notifications()->where('data->demo', true)->count(),
            'browser_sessions' => DB::table('sessions')
                ->where('id', DemoAccountSeeder::SESSION_ID)
                ->where('user_id', $user->id)
                ->count(),
            'sign_ins' => $user->signIns()
                ->whereIn('ip_address', ['192.0.2.11', '198.51.100.21', '203.0.113.31'])
                ->count(),
        ];
    }
}
