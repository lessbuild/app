<?php

namespace Tests\Feature;

use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\SshKeyPair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DigitalOceanSshKeyReuseTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    private string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $blob = 'lessbuild-existing-public-key-material';
        $this->publicKey = 'ssh-ed25519 '.base64_encode($blob).' generated-by-lessbuild';
        $this->fingerprint = implode(':', str_split(md5($blob), 2));
        $this->mock(SshKeyPair::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publicKey')->once()->andReturn($this->publicKey);
            $mock->shouldReceive('privateKey')->once()->andReturn('private-key');
        });
    }

    public function test_existing_matching_key_is_reused_for_server_creation(): void
    {
        [$user, $provider] = $this->userAndProvider();
        $this->fakeExistingKey();

        $this->actingAs($user)
            ->post(route('servers.store'), $this->serverPayload($provider))
            ->assertRedirect();

        $server = Server::query()->sole();
        $this->assertSame($this->fingerprint, $server->ssh_fingerprint);
        $this->assertFalse($server->ssh_key_owned);
        $this->assertSame('9876', $server->identifier);
        Queue::assertPushed(InitialiseServerJob::class);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.digitalocean.com/v2/account/keys/'.$this->fingerprint);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_failed_droplet_creation_never_deletes_a_reused_key(): void
    {
        [$user, $provider] = $this->userAndProvider();
        $this->fakeExistingKey(dropletFailure: true);

        $this->actingAs($user)
            ->post(route('servers.store'), $this->serverPayload($provider))
            ->assertRedirect();

        $server = Server::query()->sole();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame($this->fingerprint, $server->ssh_fingerprint);
        $this->assertFalse($server->ssh_key_owned);
        Queue::assertNotPushed(InitialiseServerJob::class);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_conflict_is_not_reconciled_to_a_different_public_key(): void
    {
        [$user, $provider] = $this->userAndProvider();
        Http::fake([
            'https://api.digitalocean.com/v2/account/keys' => Http::response(['message' => 'SSH key is already in use.'], 422),
            'https://api.digitalocean.com/v2/account/keys/*' => Http::response([
                'ssh_key' => [
                    'fingerprint' => $this->fingerprint,
                    'public_key' => 'ssh-ed25519 '.base64_encode('different-key-material'),
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('servers.store'), $this->serverPayload($provider))
            ->assertRedirect();

        $server = Server::query()->sole();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertNull($server->ssh_fingerprint);
        $this->assertStringContainsString('SSH key is already in use.', $server->provisioning_error);
        Queue::assertNotPushed(InitialiseServerJob::class);
        Http::assertSentCount(2);
    }

    private function fakeExistingKey(bool $dropletFailure = false): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account/keys' => Http::response(['message' => 'SSH key is already in use.'], 422),
            'https://api.digitalocean.com/v2/account/keys/*' => Http::response([
                'ssh_key' => [
                    'fingerprint' => $this->fingerprint,
                    'public_key' => 'ssh-ed25519 '.explode(' ', $this->publicKey)[1].' account-comment',
                ],
            ]),
            'https://api.digitalocean.com/v2/droplets' => $dropletFailure
                ? Http::response(['message' => 'The selected region is unavailable.'], 422)
                : Http::response(['droplet' => [
                    'id' => 9876,
                    'name' => 'reused-key-server',
                    'region' => ['name' => 'New York 1'],
                    'size' => ['slug' => 's-1vcpu-1gb'],
                    'image' => ['name' => 'Ubuntu 22.04'],
                    'networks' => ['v4' => []],
                ]], 202),
        ]);
    }

    /**
     * @return array{User, Provider}
     */
    private function userAndProvider(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);

        return [$user, $provider];
    }

    private function serverPayload(Provider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'type' => ServerTypeEnum::app->value,
            'name' => 'Reused Key Server',
            'region' => 'nyc1',
            'image' => 'ubuntu-22-04-x64',
            'size' => 's-1vcpu-1gb',
        ];
    }
}
