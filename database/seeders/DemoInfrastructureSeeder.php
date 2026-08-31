<?php

namespace Database\Seeders;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Region;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Size;
use App\Models\User;
use App\Models\Website;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoInfrastructureSeeder extends Seeder
{
    public function run(): void
    {
        Model::withoutEvents(function (): void {
            DB::transaction(function (): void {
                $user = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();
                $providers = $this->providers($user);
                $recipes = $this->recipes($user);
                $servers = $this->servers($user, $providers[Provider::TYPE_DIGITALOCEAN]);

                $servers['active']->recipes()->sync([
                    $recipes['packages']->id => ['position' => 0],
                    $recipes['worker']->id => ['position' => 1],
                ]);
                $servers['active']->captureProvisioningRecipes();

                $websites = $this->websites($user, $servers);
                $this->repositories($user, $providers, $websites);
                $this->cloudCatalog();
            });
        });
    }

    /** @return array<string, Provider> */
    private function providers(User $user): array
    {
        $definitions = [
            Provider::TYPE_DIGITALOCEAN => ['DigitalOcean', Provider::CONNECTION_HEALTHY],
            Provider::TYPE_GITHUB => ['GitHub', Provider::CONNECTION_HEALTHY],
            Provider::TYPE_GITLAB => ['GitLab', Provider::CONNECTION_FAILED],
            Provider::TYPE_BITBUCKET => ['Bitbucket', null],
        ];
        $providers = [];

        foreach ($definitions as $type => [$label, $status]) {
            $provider = Provider::withTrashed()->firstOrNew([
                'user_id' => $user->id,
                'name' => DemoSeeder::PREFIX.$label,
            ]);
            $provider->fill([
                'provider' => $type,
                'token' => "demo-{$type}-token-not-valid",
                'description' => "Demo {$label} provider for testing inventory, filters, exports, and health states.",
                'connection_status' => $status,
                'connection_checked_at' => $status === null ? null : now()->subMinutes(15),
                'connection_monitoring_enabled' => false,
            ]);
            $provider->deleted_at = null;
            $provider->save();
            $providers[$type] = $provider;
        }

        return $providers;
    }

    /** @return array<string, Recipe> */
    private function recipes(User $user): array
    {
        return [
            'packages' => $user->recipes()->updateOrCreate(
                ['name' => DemoSeeder::PREFIX.'Install image tools'],
                [
                    'description' => 'Installs image-processing packages during server provisioning.',
                    'script' => "apt-get update\napt-get install -y imagemagick webp",
                ],
            ),
            'worker' => $user->recipes()->updateOrCreate(
                ['name' => DemoSeeder::PREFIX.'Configure queue worker'],
                [
                    'description' => 'Creates a sample systemd worker definition for deployment testing.',
                    'script' => "systemctl daemon-reload\nsystemctl enable demo-worker.service",
                ],
            ),
        ];
    }

    /** @return array<string, Server> */
    private function servers(User $user, Provider $provider): array
    {
        $active = $user->servers()->updateOrCreate(
            ['name' => DemoSeeder::PREFIX.'Production application'],
            [
                'display_name' => DemoSeeder::PREFIX.'Primary production',
                'provider_id' => $provider->id,
                'identifier' => 900001,
                'type' => ServerTypeEnum::app,
                'region' => 'demo-nyc3',
                'image' => 'ubuntu-24-04-x64',
                'size' => 'demo-s-2vcpu-4gb',
                'ssh_fingerprint' => 'demo:ed25519:fingerprint',
                'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDemoOnlyKey lessbuild-demo',
                'ssh_private_key' => 'DEMO PRIVATE KEY - NOT VALID',
                'ssh_key_owned' => false,
                'mysql_root_password' => 'demo-mysql-root-password',
                'setup_stage' => app(ServerProvisioningPlan::class)->finalStage(ServerTypeEnum::app),
                'setup' => true,
                'public_ip' => '203.0.113.10',
                'private_ip' => '10.0.0.10',
                'provisioning_status' => Server::STATUS_ACTIVE,
                'provisioning_error' => null,
                'provisioning_failure_phase' => null,
                'provisioned_at' => now()->subDays(30),
                'provisioning_process_id' => null,
                'provisioning_process_path' => null,
                'initialization_token' => null,
            ],
        );
        $failed = $user->servers()->updateOrCreate(
            ['name' => DemoSeeder::PREFIX.'Failed worker'],
            [
                'display_name' => null,
                'provider_id' => $provider->id,
                'identifier' => 900002,
                'type' => ServerTypeEnum::worker,
                'region' => 'demo-sfo3',
                'image' => 'ubuntu-24-04-x64',
                'size' => 'demo-s-1vcpu-1gb',
                'setup_stage' => 4,
                'setup' => false,
                'public_ip' => '198.51.100.20',
                'private_ip' => '10.0.0.20',
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => 'Demo provisioning stopped while installing the Node.js runtime.',
                'provisioning_failure_phase' => Server::FAILURE_REMOTE,
                'provisioned_at' => null,
                'provisioning_process_id' => null,
                'provisioning_process_path' => null,
            ],
        );

        $servers = ['active' => $active, 'failed' => $failed];
        $provisioningStates = [
            'queued' => [
                'name' => 'Queued application',
                'identifier' => 900003,
                'type' => ServerTypeEnum::app,
                'region' => 'demo-nyc3',
                'size' => 'demo-s-2vcpu-4gb',
                'status' => Server::STATUS_QUEUED,
                'setup_stage' => 0,
                'public_ip' => null,
                'private_ip' => null,
            ],
            'waiting_for_ip' => [
                'name' => 'Waiting for IP',
                'identifier' => 900004,
                'type' => ServerTypeEnum::app,
                'region' => 'demo-nyc3',
                'size' => 'demo-s-2vcpu-4gb',
                'status' => Server::STATUS_WAITING_FOR_IP,
                'setup_stage' => 0,
                'public_ip' => null,
                'private_ip' => null,
            ],
            'provisioning' => [
                'name' => 'Provisioning worker',
                'identifier' => 900005,
                'type' => ServerTypeEnum::worker,
                'region' => 'demo-sfo3',
                'size' => 'demo-s-1vcpu-1gb',
                'status' => Server::STATUS_PROVISIONING,
                'setup_stage' => 3,
                'public_ip' => '192.0.2.30',
                'private_ip' => '10.0.0.30',
            ],
        ];

        foreach ($provisioningStates as $key => $definition) {
            $servers[$key] = $user->servers()->updateOrCreate(
                ['name' => DemoSeeder::PREFIX.$definition['name']],
                [
                    'display_name' => null,
                    'provider_id' => $provider->id,
                    'identifier' => $definition['identifier'],
                    'type' => $definition['type'],
                    'region' => $definition['region'],
                    'image' => 'ubuntu-24-04-x64',
                    'size' => $definition['size'],
                    'ssh_fingerprint' => null,
                    'ssh_public_key' => null,
                    'ssh_private_key' => null,
                    'ssh_key_owned' => false,
                    'mysql_root_password' => null,
                    'recipe_snapshot' => null,
                    'setup_stage' => $definition['setup_stage'],
                    'setup' => false,
                    'public_ip' => $definition['public_ip'],
                    'private_ip' => $definition['private_ip'],
                    'provisioning_status' => $definition['status'],
                    'provisioning_error' => null,
                    'provisioning_failure_phase' => null,
                    'provisioned_at' => null,
                    'provisioning_process_id' => null,
                    'provisioning_process_path' => null,
                ],
            );
        }

        return $servers;
    }

    /**
     * @param  array<string, Server>  $servers
     * @return array<string, Website>
     */
    private function websites(User $user, array $servers): array
    {
        $common = [
            'server_id' => $servers['active']->id,
            'setup_stage' => app(WebsiteProvisioningPlan::class)->finalStage(),
            'environment' => "APP_ENV=production\nAPP_DEBUG=false\nDEMO_FIXTURE=true",
            'database_password' => 'demo-database-password',
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioning_error' => null,
            'provisioned_at' => now()->subDays(20),
            'release_retention' => 7,
        ];
        $healthy = Website::withTrashed()->updateOrCreate(
            ['user_id' => $user->id, 'deployment_slug' => 'demo-storefront'],
            [
                ...$common,
                'name' => DemoSeeder::PREFIX.'Storefront',
                'description' => 'Healthy production-style website with deployment checks enabled.',
                'url' => 'demo-storefront.example.com',
                'health_check_enabled' => true,
                'health_monitoring_enabled' => false,
                'health_check_path' => '/up',
                'health_status' => Website::HEALTH_HEALTHY,
                'health_failure_count' => 0,
                'health_last_checked_at' => now()->subMinute(),
                'health_last_error' => null,
                'previous_server_id' => null,
                'placement_cleanup_error' => null,
                'deleted_at' => null,
            ],
        );
        $unhealthy = Website::withTrashed()->updateOrCreate(
            ['user_id' => $user->id, 'deployment_slug' => 'demo-status'],
            [
                ...$common,
                'name' => DemoSeeder::PREFIX.'Status page',
                'description' => 'Website fixture showing an active service with a failing health check.',
                'url' => 'demo-status.example.com',
                'health_check_enabled' => true,
                'health_monitoring_enabled' => false,
                'health_check_path' => '/health',
                'health_status' => Website::HEALTH_UNHEALTHY,
                'health_failure_count' => 3,
                'health_last_checked_at' => now()->subMinutes(2),
                'health_last_error' => 'Demo health endpoint returned HTTP 503.',
                'previous_server_id' => null,
                'placement_cleanup_error' => null,
                'deleted_at' => null,
            ],
        );
        $failed = Website::withTrashed()->updateOrCreate(
            ['user_id' => $user->id, 'deployment_slug' => 'demo-provisioning-failure'],
            [
                ...$common,
                'name' => DemoSeeder::PREFIX.'Provisioning failure',
                'description' => 'Failed website fixture for retry and incident-review screens.',
                'url' => 'demo-failed.example.com',
                'setup_stage' => 2,
                'provisioning_status' => Website::STATUS_FAILED,
                'provisioning_error' => 'Demo Caddy configuration validation failed.',
                'provisioned_at' => null,
                'health_check_enabled' => false,
                'health_monitoring_enabled' => false,
                'health_check_path' => '/',
                'health_status' => Website::HEALTH_UNKNOWN,
                'health_failure_count' => 0,
                'health_last_checked_at' => null,
                'health_last_error' => null,
                'previous_server_id' => $servers['failed']->id,
                'placement_cleanup_error' => 'Demo cleanup could not reach the previous server.',
                'deleted_at' => null,
            ],
        );

        $provisioningStates = [
            'queued' => [
                'slug' => 'demo-queued-site',
                'name' => 'Queued website',
                'description' => 'Website fixture waiting for its provisioning worker.',
                'url' => 'demo-queued.example.com',
                'status' => Website::STATUS_QUEUED,
                'setup_stage' => 0,
            ],
            'provisioning' => [
                'slug' => 'demo-provisioning-site',
                'name' => 'Provisioning website',
                'description' => 'Website fixture partway through its provisioning plan.',
                'url' => 'demo-provisioning.example.com',
                'status' => Website::STATUS_PROVISIONING,
                'setup_stage' => 2,
            ],
        ];
        $websites = ['healthy' => $healthy, 'unhealthy' => $unhealthy, 'failed' => $failed];

        foreach ($provisioningStates as $key => $definition) {
            $websites[$key] = Website::withTrashed()->updateOrCreate(
                ['user_id' => $user->id, 'deployment_slug' => $definition['slug']],
                [
                    ...$common,
                    'name' => DemoSeeder::PREFIX.$definition['name'],
                    'description' => $definition['description'],
                    'url' => $definition['url'],
                    'setup_stage' => $definition['setup_stage'],
                    'provisioning_status' => $definition['status'],
                    'provisioned_at' => null,
                    'health_check_enabled' => false,
                    'health_monitoring_enabled' => false,
                    'health_check_path' => '/',
                    'health_status' => Website::HEALTH_UNKNOWN,
                    'health_failure_count' => 0,
                    'health_last_checked_at' => null,
                    'health_last_error' => null,
                    'previous_server_id' => null,
                    'placement_cleanup_error' => null,
                    'deleted_at' => null,
                ],
            );
        }

        return $websites;
    }

    /**
     * @param  array<string, Provider>  $providers
     * @param  array<string, Website>  $websites
     */
    private function repositories(User $user, array $providers, array $websites): void
    {
        $definitions = [
            'github' => [
                'provider' => $providers[Provider::TYPE_GITHUB],
                'website' => $websites['healthy'],
                'name' => 'Storefront repository',
                'url' => 'github.com/lessbuild/demo-storefront.git',
                'branch' => 'main',
                'pending' => false,
            ],
            'gitlab' => [
                'provider' => $providers[Provider::TYPE_GITLAB],
                'website' => $websites['unhealthy'],
                'name' => 'Status repository',
                'url' => 'gitlab.com/lessbuild/demo-status.git',
                'branch' => 'production',
                'pending' => true,
            ],
            'bitbucket' => [
                'provider' => $providers[Provider::TYPE_BITBUCKET],
                'website' => $websites['failed'],
                'name' => 'Worker repository',
                'url' => 'bitbucket.org/lessbuild/demo-worker.git',
                'branch' => 'release',
                'pending' => false,
            ],
        ];

        foreach ($definitions as $key => $definition) {
            Repository::withTrashed()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => DemoSeeder::PREFIX.$definition['name'],
                ],
                [
                    'provider_id' => $definition['provider']->id,
                    'website_id' => $definition['website']->id,
                    'setup_stage' => match ($key) {
                        'github' => 10,
                        'gitlab' => 9,
                        default => 0,
                    },
                    'url' => $definition['url'],
                    'branch' => $definition['branch'],
                    'description' => "Demo {$key} repository with deployment history and safe fixture credentials.",
                    'build_commands' => "npm ci\nnpm run build",
                    'post_deployment_commands' => "php artisan migrate --force\nphp artisan cache:clear",
                    'webhook_secret' => "demo-{$key}-webhook-secret",
                    'webhook_enabled' => true,
                    'webhook_pending' => $definition['pending'],
                    'webhook_pending_revision' => $definition['pending'] ? str_repeat('e', 40) : null,
                    'webhook_pending_commit_message' => $definition['pending'] ? 'Demo pending status-page release' : null,
                    'webhook_last_received_at' => now()->subHours(2),
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function cloudCatalog(): void
    {
        $region = Region::withTrashed()->updateOrCreate(
            ['slug' => 'demo-nyc3'],
            ['name' => 'Demo New York 3', 'deleted_at' => null],
        );
        $size = Size::withTrashed()->updateOrCreate(
            ['slug' => 'demo-s-2vcpu-4gb'],
            [
                'description' => 'Demo 2 vCPU / 4 GB plan',
                'memory' => 4096,
                'vcpus' => 2,
                'disk' => 80,
                'transfer' => 4,
                'price_monthly' => 24,
                'price_hourly' => 0.0357,
                'deleted_at' => null,
            ],
        );
        DB::table('region_size')->updateOrInsert(
            ['region_id' => $region->id, 'size_id' => $size->id],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }
}
