<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ImportWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_limits' => false]);
    }

    public function test_existing_application_directory_is_verified_and_imported_without_provisioning(): void
    {
        $user = User::factory()->create();
        $server = $user->workspaceServers()->create([
            'user_id' => $user->id, 'name' => 'imported', 'display_name' => 'Imported', 'region' => 'External', 'image' => 'Ubuntu', 'size' => 'Custom',
            'public_ip' => '192.0.2.8', 'ssh_private_key' => 'private', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'organization_id' => $user->current_organization_id, 'provisioning_status' => Server::STATUS_ACTIVE]);
        $process = new Process(['true']);
        $process->run();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with("test -d '/var/www/existing-app' && test -r '/var/www/existing-app'")->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->withArgs(fn (Server $value): bool => $value->is($server))->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($user)->post(route('websites.import.store'), [
            'server_id' => $server->id,
            'name' => 'Existing app',
            'url' => 'existing.example.com',
            'deployment_slug' => 'existing-app',
            'description' => 'Existing production application',
        ])->assertRedirect()->assertSessionHas('success');

        $website = Website::query()->sole();
        $this->assertSame(Website::STATUS_ACTIVE, $website->provisioning_status);
        $this->assertFalse($website->health_check_enabled);
        $this->assertSame('existing-app', $website->deployment_slug);
        $this->assertNotNull($website->provisioned_at);
    }

    public function test_missing_remote_directory_is_rejected(): void
    {
        $user = User::factory()->create();
        $server = $user->workspaceServers()->create([
            'user_id' => $user->id, 'name' => 'imported', 'display_name' => 'Imported', 'region' => 'External', 'image' => 'Ubuntu', 'size' => 'Custom',
            'public_ip' => '192.0.2.9', 'ssh_private_key' => 'private', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $process = new Process(['false']);
        $process->run();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($user)->from(route('websites.import.create'))->post(route('websites.import.store'), [
            'server_id' => $server->id, 'name' => 'Missing', 'url' => 'missing.example.com', 'deployment_slug' => 'missing', 'description' => 'Missing app',
        ])->assertRedirect(route('websites.import.create'))->assertSessionHasErrors('deployment_slug');
        $this->assertDatabaseCount('websites', 0);
    }
}
