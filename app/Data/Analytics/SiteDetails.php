<?php

declare(strict_types=1);

namespace App\Data\Analytics;

final readonly class SiteDetails
{
    /**
     * The settings of an analytics site.
     *
     * @param  string  $name  The site's name.
     * @param  list<string>  $domains  hostnames the tracker may send from (normalised)
     * @param  string  $timezone  The timezone reports use to decide where a day starts.
     * @param  list<string>  $excludedPaths  path patterns never recorded, e.g. /admin/*
     * @param  ?string  $environmentId  The project environment the site belongs to, if any.
     */
    public function __construct(
        public string $name,
        public array $domains,
        public string $timezone = 'UTC',
        public array $excludedPaths = [],
        public ?string $environmentId = null,
    ) {}
}
