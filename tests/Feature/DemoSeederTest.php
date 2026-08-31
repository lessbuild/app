<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Models\Website;
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
        $this->assertSame(4, $user->providers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertEqualsCanonicalizing([
            Provider::TYPE_DIGITALOCEAN,
            Provider::TYPE_GITHUB,
            Provider::TYPE_GITLAB,
            Provider::TYPE_BITBUCKET,
        ], $user->providers()->pluck('provider')->all());
        $this->assertEqualsCanonicalizing([
            Provider::CONNECTION_HEALTHY,
            Provider::CONNECTION_FAILED,
            null,
        ], $user->providers()->distinct()->pluck('connection_status')->all());
        $this->assertSame(2, $user->recipes()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertSame(5, $user->servers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertEqualsCanonicalizing([
            Server::STATUS_QUEUED,
            Server::STATUS_WAITING_FOR_IP,
            Server::STATUS_PROVISIONING,
            Server::STATUS_ACTIVE,
            Server::STATUS_FAILED,
        ], $user->servers()->pluck('provisioning_status')->all());
        $this->assertSame(3, $user->servers()->whereIn('provisioning_status', Server::ACTIVE_PROVISIONING_STATUSES)->count());
        $this->assertSame(5, $user->websites()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
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
        $this->assertSame(3, $user->repositories()->where('name', 'like', DemoSeeder::PREFIX.'%')->count());
        $this->assertSame(6, $user->builds()->count());
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
        $this->assertEqualsCanonicalizing([
            ServerCommandExecution::STATUS_QUEUED,
            ServerCommandExecution::STATUS_SUCCEEDED,
            ServerCommandExecution::STATUS_FAILED,
            ServerCommandExecution::STATUS_CANCELED,
        ], ServerCommandExecution::query()->where('user_id', $user->id)->distinct()->pluck('status')->all());
        $demoRerun = ServerCommandExecution::query()
            ->where('user_id', $user->id)
            ->whereNotNull('rerun_from_execution_id')
            ->sole();
        $this->assertSame($demoRerun->command, $demoRerun->rerunFrom->command);
        $this->assertEqualsCanonicalizing(
            ['deployment', 'website', 'server', 'command', 'provider', 'general'],
            $user->events()->pluck('category')->all(),
        );
        $this->assertSame(5, $user->notifications()->count());
        $this->assertSame(3, $user->unreadNotifications()->count());
        $this->assertSame(2, $user->readNotifications()->count());
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
        $this->assertNotSame($execution->command, DB::table('server_command_executions')->where('id', $execution->id)->value('command'));

        $this->post(route('login'), [
            'email' => DemoSeeder::EMAIL,
            'password' => 'password',
        ])->assertRedirect(route('websites.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_seeded_feature_pages_render_for_the_demo_owner(): void
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $user = User::query()->where('email', DemoSeeder::EMAIL)->sole();
        $provider = $user->providers()->where('name', DemoSeeder::PREFIX.'GitHub')->sole();
        $server = $user->servers()->where('name', DemoSeeder::PREFIX.'Production application')->sole();
        $website = $user->websites()->where('deployment_slug', 'demo-storefront')->sole();
        $repository = $user->repositories()->where('name', DemoSeeder::PREFIX.'Storefront repository')->sole();
        $build = $repository->builds()->latest()->firstOrFail();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Infrastructure provisioning')
            ->assertSee('5 resources are being prepared')
            ->assertSee(DemoSeeder::PREFIX.'Waiting for IP')
            ->assertSee(DemoSeeder::PREFIX.'Provisioning website');

        foreach ([
            route('dashboard'),
            route('providers.index'),
            route('providers.show', $provider),
            route('recipes.index'),
            route('servers.index'),
            route('servers.show', $server),
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
            'recipes' => $user->recipes()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'servers' => $user->servers()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'websites' => $user->websites()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'repositories' => $user->repositories()->where('name', 'like', DemoSeeder::PREFIX.'%')->count(),
            'builds' => $user->builds()->count(),
            'deliveries' => RepositoryWebhookDelivery::query()
                ->whereHas('repository', fn ($query) => $query->where('user_id', $user->id))
                ->count(),
            'commands' => ServerCommandExecution::query()->where('user_id', $user->id)->count(),
            'events' => $user->events()->where('event', 'like', 'Demo:%')->count(),
            'notifications' => $user->notifications()->where('data->demo', true)->count(),
        ];
    }
}
