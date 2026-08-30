<?php

namespace Tests\Feature;

use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_cloud_cleanup_preserves_the_server_and_its_resources(): void
    {
        Queue::fake();
        [$user, $server, $website, $repository] = $this->resources();
        Http::fake([
            'https://api.digitalocean.com/v2/account/keys/*' => Http::response([], 204),
            'https://api.digitalocean.com/v2/droplets/*' => Http::response([], 503),
        ]);

        $this->actingAs($user)
            ->from(route('servers.show', $server))
            ->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.show', $server))
            ->assertSessionHas('error', 'The server could not be deleted: DigitalOcean could not delete the cloud server.');

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertDatabaseHas('websites', ['id' => $website->id]);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'deleted_at' => null]);
        $this->assertDatabaseCount('logs', 1);
        Queue::assertNotPushed(DeleteWebsiteFromCaddyJob::class);
    }

    public function test_successful_cloud_cleanup_removes_the_complete_local_resource_tree(): void
    {
        Queue::fake();
        [$user, $server, $website, $repository, $build] = $this->resources();
        $previousServer = $user->servers()->create([
            'name' => 'Previous server',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website->update(['previous_server_id' => $previousServer->id]);
        Http::fake([
            'https://api.digitalocean.com/v2/account/keys/*' => Http::response([], 204),
            'https://api.digitalocean.com/v2/droplets/*' => Http::response([], 204),
        ]);

        $this->actingAs($user)->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'))
            ->assertSessionHas('success', 'Server deleted successfully.');

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
        $this->assertDatabaseMissing('repositories', ['id' => $repository->id]);
        $this->assertDatabaseMissing('builds', ['id' => $build->id]);
        $this->assertDatabaseCount('logs', 0);
        Queue::assertNotPushed(DeleteWebsiteFromCaddyJob::class);
        Queue::assertPushed(CleanupWebsitePlacementJob::class, fn (CleanupWebsitePlacementJob $job): bool => $job->websiteId === $website->id && $job->serverId === $previousServer->id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.digitalocean.com/v2/droplets/12345');
    }

    public function test_already_absent_cloud_resources_make_deletion_idempotent(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        Http::fake([
            'https://api.digitalocean.com/v2/account/keys/*' => Http::response([], 404),
            'https://api.digitalocean.com/v2/droplets/*' => Http::response([], 404),
        ]);

        $this->actingAs($user)->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'));

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    public function test_deletion_preserves_a_user_managed_ssh_key(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $server->update(['ssh_key_owned' => false]);
        Http::fake([
            'https://api.digitalocean.com/v2/droplets/*' => Http::response([], 204),
        ]);

        $this->actingAs($user)
            ->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'));

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.digitalocean.com/v2/droplets/12345');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/account/keys/'));
    }

    public function test_flash_messages_are_visible_in_the_application_layout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['error' => 'Visible operation failure'])
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Visible operation failure');
    }

    private function resources(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $provider->id,
            'identifier' => 12345,
            'name' => 'Production',
            'ssh_fingerprint' => 'fingerprint-123',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $sourceProvider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $sourceProvider->id,
            'website_id' => $website->id,
            'name' => 'Application',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $build->logs()->create(['type' => 'deployment', 'log' => 'Deployment output']);

        return [$user, $server, $website, $repository, $build];
    }
}
