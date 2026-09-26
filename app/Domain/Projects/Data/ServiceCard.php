<?php

declare(strict_types=1);

namespace App\Domain\Projects\Data;

final readonly class ServiceCard
{
    public function __construct(
        public string $key,
        public string $name,
        public string $tagline,
        public string $icon,
        public bool $enabled,
        /** The viewer may open this service's pages. */
        public bool $canUse,
        /** The viewer may turn it on or off. */
        public bool $canManage,
        public string $url,
    ) {}
}
