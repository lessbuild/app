<?php

declare(strict_types=1);

namespace App\Platform;

use InvalidArgumentException;

/** Every service the platform offers, in registration (display) order. */
final class ServiceRegistry
{
    /** @var array<string, PlatformService> */
    private array $services = [];

    public function register(PlatformService $service): void
    {
        if (isset($this->services[$service->key()])) {
            throw new InvalidArgumentException("A service with the key [{$service->key()}] is already registered.");
        }
        $this->services[$service->key()] = $service;
    }

    /** @return list<PlatformService> */
    public function all(): array
    {
        return array_values($this->services);
    }

    public function has(string $key): bool
    {
        return isset($this->services[$key]);
    }

    public function find(string $key): ?PlatformService
    {
        return $this->services[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->services);
    }
}
