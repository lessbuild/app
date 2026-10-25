<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Contracts\Infrastructure\ResizableServerProvider;
use App\Contracts\Infrastructure\ServerProvider;
use App\Contracts\Infrastructure\SnapshotsServers;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use RuntimeException;

/** A cloud that records what it was asked to do. Set `$failCreate` or `$failDelete` to make it refuse. */
final class FakeServerProvider implements ResizableServerProvider, ServerProvider, SnapshotsServers
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

    /** @var 'ready'|'not_ready'|'unknown'|null What the provider reports; null works it out from the IP. */
    public ?string $readiness = null;

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
        return new CloudServerData((string) $identifier, 'web-1', 'fra1', 's-1', 'ubuntu', $this->publicIp, '10.0.0.5', 'active', $this->readiness ?? ($this->publicIp === null ? 'not_ready' : 'ready'));
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

    /** @var list<array<string, mixed>> */
    public array $sizes = [];

    public function sizes(): array
    {
        return $this->sizes;
    }

    public function images(): array
    {
        return [];
    }

    /**
     * The snapshots taken, as [server, name].
     *
     * @var list<array{0: int|string, 1: string}>
     */
    public array $snapshots = [];

    /**
     * The snapshots deleted.
     *
     * @var list<string>
     */
    public array $deletedSnapshots = [];

    /**
     * Record a snapshot and return its ID.
     *
     * @param  int|string  $identifier
     * @param  string  $name
     * @return string
     */
    public function snapshotServer(int|string $identifier, string $name): string
    {
        $this->snapshots[] = [$identifier, $name];

        return 'snap-'.count($this->snapshots);
    }

    /**
     * Record a deleted snapshot.
     *
     * @param  string  $snapshot
     * @return bool
     */
    public function deleteSnapshot(string $snapshot): bool
    {
        $this->deletedSnapshots[] = $snapshot;

        return true;
    }

    /**
     * The resize steps recorded, in order: ['off', id], ['resize', id, size, disk], ['on', id].
     *
     * @var list<array<int, mixed>>
     */
    public array $resizeSteps = [];

    public bool $failResize = false;

    /**
     * Record a power-off.
     *
     * @param  int|string  $identifier
     * @return void
     */
    public function powerOffServer(int|string $identifier): void
    {
        $this->resizeSteps[] = ['off', $identifier];
    }

    /**
     * Record a resize, or fail when asked to.
     *
     * @param  int|string  $identifier
     * @param  string  $size
     * @param  bool  $resizeDisk
     * @return void
     */
    public function resizeServer(int|string $identifier, string $size, bool $resizeDisk): void
    {
        if ($this->failResize) {
            throw new RuntimeException('Fake Cloud resize failed.');
        }
        $this->resizeSteps[] = ['resize', $identifier, $size, $resizeDisk];
    }

    /**
     * Record a power-on.
     *
     * @param  int|string  $identifier
     * @return void
     */
    public function powerOnServer(int|string $identifier): void
    {
        $this->resizeSteps[] = ['on', $identifier];
    }
}
