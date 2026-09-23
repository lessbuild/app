<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectProductLink;

final class ProjectProductLinkRegistry
{
    /** @var array<string, ProjectProductLink> */
    private array $links = [];

    public function register(string $product, ProjectProductLink $link): void
    {
        $this->links[$product] = $link;
    }

    public function get(string $product): ?ProjectProductLink
    {
        return $this->links[$product] ?? null;
    }
}
