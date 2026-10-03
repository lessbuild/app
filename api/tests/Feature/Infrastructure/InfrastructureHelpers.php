<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Enums\ProviderType;
use App\Models\Provider;
use App\Models\Server;
use App\Services\Infrastructure\RemoteScriptRunner;
use App\Services\Infrastructure\ServerDiscovery;
use App\Services\Infrastructure\ServerProviderResolver;
use App\Services\Infrastructure\SshHostIdentity;
use App\Services\Infrastructure\SshKeyPair;

/** Fakes for everything that would reach a cloud API or a server over SSH. */
trait InfrastructureHelpers
{
    protected FakeServerProvider $cloud;

    protected FakeRemoteScriptRunner $scripts;

    protected FakeServerShell $shell;

    protected function fakeInfrastructure(): void
    {
        $this->cloud = new FakeServerProvider;
        $cloud = $this->cloud;
        $this->app->instance(ServerProviderResolver::class, new class($cloud) extends ServerProviderResolver
        {
            public function __construct(private readonly FakeServerProvider $cloud) {}

            public function resolve(Provider $provider): ServerProvider
            {
                return $this->cloud;
            }

            public function resolveCredentials(ProviderType $type, string $token): ServerProvider
            {
                return $this->cloud;
            }
        });
        $this->app->instance(SshKeyPair::class, new class extends SshKeyPair
        {
            public function __construct() {}

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAAFAKEPUBLIC generated';
            }

            public function privateKey(): string
            {
                return "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----";
            }
        });
        $this->app->instance(SshHostIdentity::class, new class extends SshHostIdentity
        {
            /** @return array{known_host: string, fingerprint: string, algorithm: string} */
            public function scan(string $host, int $port): array
            {
                return ['known_host' => "{$host} ssh-ed25519 AAAAHOST", 'fingerprint' => 'SHA256:fakehost', 'algorithm' => 'ssh-ed25519'];
            }
        });
        $this->shell = new FakeServerShell;
        $this->app->instance(\App\Services\Infrastructure\ServerShell::class, $this->shell);
        $this->scripts = new FakeRemoteScriptRunner;
        $this->app->instance(RemoteScriptRunner::class, $this->scripts);
    }

    /** @param array<string, mixed> $report */
    protected function fakeDiscovery(array $report = []): void
    {
        $this->app->instance(ServerDiscovery::class, new FakeServerDiscovery($report));
    }

    /** A signed provisioning callback URL for a server's current attempt. */
    protected function callbackUrl(Server $server, string $event): string
    {
        return \App\Services\Infrastructure\ProvisioningCallbackUrl::{match ($event) {
            'status' => 'serverStatus', 'failed' => 'serverFailure', default => 'serverLog'
        }}($server);
    }
}
