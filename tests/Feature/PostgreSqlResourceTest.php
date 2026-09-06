<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ConfigureResourcesScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgreSqlResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_postgresql_resource_creates_framework_database_variables(): void
    {
        config()->set('billing.enforce_entitlements', false);
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Storefront',
            'slug' => 'storefront',
        ]);
        $server = $owner->servers()->create([
            'name' => 'production',
            'public_ip' => '203.0.113.20',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Storefront',
            'description' => 'Production storefront',
            'url' => 'storefront.example.com',
            'environment' => 'APP_ENV=production',
            'database_password' => "safe'password",
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $environment = $project->environments()->create([
            'server_id' => $server->id,
            'website_id' => $website->id,
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
        ]);

        $this->actingAs($owner)->post(route('environments.resources.store', $environment), [
            'name' => 'primary_database',
            'type' => 'postgresql',
            'is_managed' => '1',
            'variables' => '',
        ])->assertRedirect();

        $resource = $environment->resources()->sole();
        $this->assertSame('postgresql', $resource->type);
        $this->assertTrue($resource->is_managed);
        $this->assertSame([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'storefront',
            'DB_USERNAME' => 'storefront',
            'DB_PASSWORD' => "safe'password",
        ], $resource->configuration['variables']);
    }

    public function test_postgresql_provisioning_script_is_idempotent_and_shell_safe(): void
    {
        $build = new Build([
            'environment_payload' => [
                'resources' => [[
                    'type' => 'postgresql',
                    'configuration' => ['variables' => [
                        'DB_HOST' => '127.0.0.1',
                        'DB_DATABASE' => 'storefront',
                        'DB_USERNAME' => 'storefront',
                        'DB_PASSWORD' => "safe'password",
                    ]],
                ]],
            ],
        ]);
        $build->id = 42;

        $script = (new ConfigureResourcesScript)->script(3, $build);
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();

        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
        $this->assertStringContainsString('apt-get install -y -qq postgresql postgresql-contrib', $script);
        $this->assertStringContainsString("PASSWORD 'safe''password'", $script);
        $this->assertStringContainsString('WHERE NOT EXISTS (SELECT FROM pg_database', $script);
        $this->assertStringContainsString('ALTER DATABASE "storefront" OWNER TO "storefront"', $script);
    }
}
