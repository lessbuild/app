<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProjectConnectionOutboxSource;
use LogicException;

final class ProjectConnectionOutboxSourceRegistry
{
    /** @var array<string, ProjectConnectionOutboxSource> */
    private array $sources = [];

    public function register(string $product, ProjectConnectionOutboxSource $source): void
    {
        if ($source->product() !== $product) {
            throw new LogicException("The {$product} outbox source declared a different product.");
        }

        if (isset($this->sources[$product]) && get_class($this->sources[$product]) !== get_class($source)) {
            throw new LogicException("A project-connection outbox source is already registered for {$product}.");
        }

        $this->sources[$product] = $source;
    }

    public function get(string $product): ?ProjectConnectionOutboxSource
    {
        return $this->sources[$product] ?? null;
    }

    /** @return array<string, ProjectConnectionOutboxSource> */
    public function all(): array
    {
        return $this->sources;
    }
}
