<?php

declare(strict_types=1);

namespace App\Platform;

use InvalidArgumentException;

/** Every service the platform offers, in registration (display) order. */
final class ServiceRegistry
{
    /**
     * The registered services keyed by their `key()`, in registration order.
     *
     * @var array<string, PlatformService>
     */
    private array $services = [];

    /**
     * Add a service. Keys are stored in the database, so a second service with the same key is a programming error and
     * throws.
     *
     * @param  PlatformService  $service
     * @return void
     */
    public function register(PlatformService $service): void
    {
        if (isset($this->services[$service->key()])) {
            throw new InvalidArgumentException("A service with the key [{$service->key()}] is already registered.");
        }
        $this->services[$service->key()] = $service;
    }

    /**
     * Get every service, in the order they were registered, which is the order the shell lists them.
     *
     * @return list<PlatformService>
     */
    public function all(): array
    {
        return array_values($this->services);
    }

    /**
     * Determine whether a key names a registered service, used to validate service keys from requests.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->services[$key]);
    }

    /**
     * Find the service with this key, or return null.
     *
     * @param  string  $key
     * @return PlatformService|null
     */
    public function find(string $key): ?PlatformService
    {
        return $this->services[$key] ?? null;
    }

    /**
     * Get every registered key, in display order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->services);
    }
}
