<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use RuntimeException;

/** A cloud that records what it was asked to do. Set `$failCreate` or `$failDelete` to make it refuse. */
final class FakeServerProvider implements ServerProvider
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<string> */
    public array $deletedServers = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    public bool $failCreate = false;

    public bool $failDelete = false;

    public ?string $publicIp = '203.0.113.50';

    public function name(): string
    {
        return 'Fake Cloud';
    }

    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData('key-1', true);
    }

    public function deleteSshKey(string $fingerprint): bool
    {
        $this->deletedKeys[] = $fingerprint;

        return ! $this->failDelete;
    }

    public function createServer(array $parameters): CloudServerData
    {
        if ($this->failCreate) {
            throw new RuntimeException('Fake Cloud server creation failed with HTTP 422.');
        }
        $this->created[] = $parameters;

        return new CloudServerData('cloud-7', (string) $parameters['name'], (string) $parameters['region'], (string) $parameters['size'], (string) $parameters['image']);
    }

    public function server(int|string $identifier): CloudServerData
    {
        return new CloudServerData((string) $identifier, 'web-1', 'fra1', 's-1', 'ubuntu', $this->publicIp, '10.0.0.5', 'active', $this->publicIp === null ? 'not_ready' : 'ready');
    }

    public function deleteServer(int|string $identifier): bool
    {
        $this->deletedServers[] = (string) $identifier;

        return ! $this->failDelete;
    }

    public function regions(): array
    {
        return [];
    }

    public function sizes(): array
    {
        return [];
    }

    public function images(): array
    {
        return [];
    }
}
