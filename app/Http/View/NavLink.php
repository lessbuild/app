<?php

declare(strict_types=1);

namespace App\Http\View;

final readonly class NavLink
{
    /** @param list<NavLink> $children */
    public function __construct(
        public string $label,
        public string $url,
        public bool $current = false,
        public ?string $icon = null,
        public array $children = [],
    ) {}
}
