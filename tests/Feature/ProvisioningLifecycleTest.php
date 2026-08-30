<?php

namespace Tests\Feature;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProvisioningLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_website_queues_provisioning_and_encrypts_its_database_password(): void
    {
        Queue::fake();
        [$user, , $server] = $this->infrastructure();
        $server->update(['provisioning_status' => Server::STATUS_ACTIVE]);

        $this->actingAs($user)->post(route('websites.store'), [
            'name' => 'Customer Portal',
            'server_id' => $server->id,
            'url' => 'portal.example.com',
            'description' => 'Customer portal',
            'environment' => 'APP_ENV=production',
        ])->assertRedirect();

        $website = Website::query()->sole();
        $this->assertSame(Website::STATUS_QUEUED, $website->provisioning_status);
        $this->assertNotEmpty($website->database_password);
        $this->assertNotSame(
            $website->database_password,
            Website::query()->toBase()->find($website->id)->database_password
        );
        Queue::assertPushed(AddWebsiteJob::class, fn ($job) => $job->website->is($website));
    }

    public function test_signed_completion_callbacks_activate_servers_and_websites(): void
    {
        Queue::fake();
        [, , $server, $website] = $this->infrastructure(withWebsite: true);

        $this->post(URL::signedRoute('callbacks.server', $server), ['status' => 12])->assertSuccessful();
        $this->post(URL::signedRoute('callbacks.website', $website), ['status' => 3])->assertSuccessful();

        $this->assertSame(Server::STATUS_ACTIVE, $server->fresh()->provisioning_status);
        $this->assertNotNull($server->fresh()->provisioned_at);
        $this->assertSame(Website::STATUS_ACTIVE, $website->fresh()->provisioning_status);
        $this->assertNotNull($website->fresh()->provisioned_at);
    }

    public function test_signed_failure_callbacks_expose_remote_errors(): void
    {
        Queue::fake();
        [, , $server, $website] = $this->infrastructure(withWebsite: true);

        $this->post(URL::signedRoute('callbacks.server.failed', $server), [
            'message' => 'Package installation failed',
            'exit_code' => 100,
        ])->assertSuccessful();
        $this->post(URL::signedRoute('callbacks.website.failed', $website), [
            'message' => 'Caddy reload failed',
            'exit_code' => 1,
        ])->assertSuccessful();

        $this->assertSame(Server::STATUS_FAILED, $server->fresh()->provisioning_status);
        $this->assertSame('Package installation failed (exit code 100)', $server->fresh()->provisioning_error);
        $this->assertSame(Website::STATUS_FAILED, $website->fresh()->provisioning_status);
        $this->assertSame('Caddy reload failed (exit code 1)', $website->fresh()->provisioning_error);
    }

    public function test_pending_server_page_does_not_attempt_an_ssh_connection(): void
    {
        Queue::fake();
        [$user, , $server] = $this->infrastructure();
        $this->assertTrue($user->can('view', $server));

        $this->actingAs($user)
            ->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('Logs will be available when provisioning finishes.')
            ->assertDontSee('Edit Server');
    }

    private function infrastructure(bool $withWebsite = false): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => 'digitalocean',
            'token' => 'secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_PROVISIONING,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = null;

        if ($withWebsite) {
            $website = $user->websites()->create([
                'server_id' => $server->id,
                'name' => 'Application',
                'description' => 'Website',
                'environment' => 'APP_ENV=production',
                'url' => 'app.test',
                'database_password' => 'database-secret',
                'provisioning_status' => Website::STATUS_PROVISIONING,
            ]);
        }

        return [$user, $provider, $server, $website];
    }
}
