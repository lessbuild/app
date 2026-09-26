<?php

declare(strict_types=1);

namespace App\Platform;

final readonly class ServiceNavItem
{
    public function __construct(
        public string $label,
        public string $url,
        /** Route name pattern that marks this item current, e.g. `projects.services.show`. */
        public string $activePattern,
    ) {}
}
