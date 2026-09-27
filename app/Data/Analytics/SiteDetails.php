<?php

declare(strict_types=1);

namespace App\Data\Analytics;

final readonly class SiteDetails
{
    /**
     * @param  list<string>  $domains  hostnames the tracker may send from (normalised)
     * @param  list<string>  $excludedPaths  path patterns never recorded, e.g. /admin/*
     */
    public function __construct(
        public string $name,
        public array $domains,
        public string $timezone = 'UTC',
        public array $excludedPaths = [],
        public ?string $environmentId = null,
    ) {}
}
