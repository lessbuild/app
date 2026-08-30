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

class ServerCreationFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mock(SshKeyPair::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publicKey')->once()->andReturn('ssh-ed25519 test-public-key');
            $mock->shouldReceive('privateKey')->once()->andReturn('test-private-key');
        });
    }

    public function test_ssh_key_creation_failure_marks_the_server_as_failed(): void
    {
        [$user, $provider] = $this->userAndProvider();

        Http::fake([
            'https://api.digitalocean.com/v2/account/keys' => Http::response([
                'message' => 'The supplied key is invalid.',
            ], 422),
        ]);

        $response = $this->actingAs($user)->post(route('servers.store'), $this->serverPayload($provider));

        $server = Server::query()->sole();
        $response->assertRedirect(route('servers.show', $server));
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame(
            'DigitalOcean request failed with HTTP 422: The supplied key is invalid.',
            $server->provisioning_error,
        );
        $this->assertNull($server->ssh_fingerprint);
        $this->assertSame(Server::FAILURE_CREATION, $server->provisioning_failure_phase);
        Queue::assertNotPushed(InitialiseServerJob::class);
        Http::assertSentCount(1);
    }

    public function test_droplet_creation_failure_removes_the_created_ssh_key(): void
    {
        [$user, $provider] = $this->userAndProvider();

        Http::fake([
            'https://api.digitalocean.com/v2/account/keys*' => Http::sequence()
                ->push(['ssh_key' => ['fingerprint' => 'fingerprint-123']], 201)
                ->push([], 204),
            'https://api.digitalocean.com/v2/droplets' => Http::response([
                'message' => 'The selected region is unavailable.',
            ], 422),
        ]);

        $response = $this->actingAs($user)->post(route('servers.store'), $this->serverPayload($provider));

        $server = Server::query()->sole();
        $response->assertRedirect(route('servers.show', $server));
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertNull($server->ssh_fingerprint);
        $this->assertSame(Server::FAILURE_CREATION, $server->provisioning_failure_phase);
        Queue::assertNotPushed(InitialiseServerJob::class);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.digitalocean.com/v2/account/keys/fingerprint-123');
    }

    public function test_failed_ssh_key_cleanup_keeps_the_fingerprint_for_later_retry(): void
    {
        [$user, $provider] = $this->userAndProvider();

        Http::fake([
            'https://api.digitalocean.com/v2/account/keys*' => Http::sequence()
                ->push(['ssh_key' => ['fingerprint' => 'fingerprint-123']], 201)
                ->push(['message' => 'Temporarily unavailable.'], 503),
            'https://api.digitalocean.com/v2/droplets' => Http::response([
                'message' => 'The selected image is unavailable.',
            ], 422),
        ]);

        $this->actingAs($user)
            ->post(route('servers.store'), $this->serverPayload($provider))
            ->assertRedirect();

        $server = Server::query()->sole();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame('fingerprint-123', $server->ssh_fingerprint);
        Queue::assertNotPushed(InitialiseServerJob::class);
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
            'name' => 'Failure Test',
            'region' => 'nyc1',
            'image' => 'ubuntu-22-04-x64',
            'size' => 's-1vcpu-1gb',
        ];
    }
}
