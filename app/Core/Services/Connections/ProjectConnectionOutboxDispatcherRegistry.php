<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProjectConnectionOutboxDispatcher;
use LogicException;

final class ProjectConnectionOutboxDispatcherRegistry
{
    /** @var array<string, ProjectConnectionOutboxDispatcher> */
    private array $dispatchers = [];

    public function register(string $product, ProjectConnectionOutboxDispatcher $dispatcher): void
    {
        if ($dispatcher->product() !== $product) {
            throw new LogicException("The {$product} outbox dispatcher declared a different product.");
        }

        if (isset($this->dispatchers[$product]) && get_class($this->dispatchers[$product]) !== get_class($dispatcher)) {
            throw new LogicException("A project-connection outbox dispatcher is already registered for {$product}.");
        }

        $this->dispatchers[$product] = $dispatcher;
    }

    public function get(string $product): ?ProjectConnectionOutboxDispatcher
    {
        return $this->dispatchers[$product] ?? null;
    }

    /** @return array<string, ProjectConnectionOutboxDispatcher> */
    public function all(): array
    {
        return $this->dispatchers;
    }
}
