<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ProvisioningCallbackUrl;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_another_users_resources(): void
    {
        Queue::fake();

        [$owner, $intruder] = User::factory()->count(2)->create();
        [$provider, $server, $website, $repository] = $this->resourcesFor($owner);

        $this->actingAs($intruder)->get(route('providers.show', $provider))->assertForbidden();
        $this->actingAs($intruder)->get(route('servers.show', $server))->assertForbidden();
        $this->actingAs($intruder)->get(route('websites.show', $website))->assertForbidden();
        $this->actingAs($intruder)->get(route('repositories.show', $repository))->assertForbidden();
        $this->actingAs($intruder)->delete(route('repositories.destroy', $repository))->assertForbidden();
        $this->actingAs($intruder)->post(route('repositories.deploy', $repository))->assertForbidden();
    }

    public function test_repository_relations_must_belong_to_the_authenticated_user(): void
    {
        Queue::fake();

        [$owner, $intruder] = User::factory()->count(2)->create();
        [$provider, , $website] = $this->resourcesFor($owner);

        $response = $this->actingAs($intruder)->post(route('repositories.store'), [
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Stolen repository',
            'url' => 'github.com/example/project.git',
            'description' => 'Should not be accepted',
        ]);

        $response->assertSessionHasErrors(['provider_id', 'website_id']);
        $this->assertDatabaseMissing('repositories', ['name' => 'Stolen repository']);
    }

    public function test_provider_tokens_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'plain-text-secret',
            'description' => 'Deploy token',
        ]);

        $this->assertSame('plain-text-secret', $provider->fresh()->token);
        $this->assertNotSame(
            'plain-text-secret',
            Provider::query()->toBase()->find($provider->id)->token
        );
        $this->assertArrayNotHasKey('token', $provider->toArray());
    }

    public function test_server_private_keys_are_encrypted_and_used_for_ssh(): void
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_public_key' => 'ssh-rsa public-key',
            'ssh_private_key' => 'private-key-contents',
        ]);

        $this->assertSame('private-key-contents', $server->fresh()->ssh_private_key);
        $this->assertNotSame(
            'private-key-contents',
            Server::query()->toBase()->find($server->id)->ssh_private_key,
        );
        $this->assertArrayNotHasKey('ssh_private_key', $server->toArray());
        $this->assertArrayNotHasKey('provisioning_token', $server->toArray());
        $this->assertArrayNotHasKey('initialization_token', $server->toArray());

        $connection = (new Runner)->server($server->fresh())->create();
        $command = $connection->getExecuteCommand('uptime');

        preg_match('/-i ([^ ]+)/', $command, $matches);
        $temporaryKey = $matches[1] ?? null;

        $this->assertNotNull($temporaryKey);
        $this->assertSame('private-key-contents', file_get_contents($temporaryKey));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($temporaryKey)), -4));

        $connection->close();
        $this->assertFileDoesNotExist($temporaryKey);
    }

    public function test_provisioning_callbacks_require_a_valid_signature(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [, $server] = $this->resourcesFor($user);

        $payload = ['status' => 3];

        $this->post(route('callbacks.server', $server), $payload)->assertForbidden();
        $this->post(ProvisioningCallbackUrl::serverStatus($server), $payload)->assertSuccessful();

        $this->assertSame(3, $server->fresh()->setup_stage);

        $this->post(ProvisioningCallbackUrl::serverStatus($server), ['status' => 1])->assertSuccessful();
        $this->assertSame(3, $server->fresh()->setup_stage);
    }

    private function resourcesFor(User $user): array
    {
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'secret',
            'description' => 'Git provider',
        ]);
        $server = $user->servers()->create(['name' => 'Production', 'provider_id' => $provider->id]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Example',
            'description' => 'Example website',
            'environment' => 'APP_ENV=production',
            'url' => 'example.com',
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Example repository',
            'url' => 'github.com/example/project.git',
            'description' => 'Example repository',
        ]);

        return [$provider, $server, $website, $repository];
    }
}
