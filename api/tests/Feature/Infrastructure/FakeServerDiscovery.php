<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Services\Infrastructure\ServerDiscovery;

/** A read-only SSH inspection that finds a supported Ubuntu server, with any facts overridden. */
final class FakeServerDiscovery extends ServerDiscovery
{
    /** @param array<string, mixed> $overrides */
    public function __construct(private readonly array $overrides = []) {}

    /**
     * @param  array{public_ip: string, ssh_port: int, ssh_private_key: string}  $configuration
     * @return array<string, mixed>
     */
    public function inspect(array $configuration): array
    {
        return [
            'known_host' => $configuration['public_ip'].' ssh-ed25519 AAAAHOST', 'fingerprint' => 'SHA256:imported', 'algorithm' => 'ssh-ed25519',
            'uid' => '0', 'os_id' => 'ubuntu', 'os_version' => '24.04', 'architecture' => 'x86_64', 'hostname' => 'legacy',
            'memory_mb' => '2048', 'disk_free_mb' => '20000', 'services' => ['nginx'], 'warnings' => ['Existing services may be reconfigured or restarted during provisioning.'],
            ...$this->overrides,
        ];
    }
}
