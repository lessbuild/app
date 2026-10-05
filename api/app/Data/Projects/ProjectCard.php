<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ProjectCard
{
    /**
     * Something is wrong right now: an incident is open.
     *
     * @var string
     */
    public const DEGRADED = 'degraded';

    /**
     * Nothing has shipped or been measured yet.
     *
     * @var string
     */
    public const SETTING_UP = 'setting_up';

    /**
     * Running with nothing open.
     *
     * @var string
     */
    public const HEALTHY = 'healthy';

    /**
     * Create a new ProjectCard instance.
     *
     * One project on the projects list.
     *
     * @param  string  $id  The project's ID.
     * @param  string  $name  The project's name.
     * @param  ?string  $description  The project's description, if it has one.
     * @param  list<string>  $serviceNames  enabled services, in registry order
     * @param  int  $environmentCount  How many environments it has.
     * @param  list<string>  $serviceKeys  the enabled services' keys, in registry order
     * @param  string  $health  HEALTHY, DEGRADED or SETTING_UP
     * @param  string  $healthLabel  the health, in words
     * @param  int  $openIncidents  how many incidents are open
     * @param  string|null  $lastDeployAt  when the last successful deploy finished (ISO 8601)
     * @param  list<int>  $visitors  visitors on each of the last ten days, oldest first; empty without Analytics
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $serviceNames,
        public int $environmentCount,
        public array $serviceKeys = [],
        public string $health = self::HEALTHY,
        public string $healthLabel = '',
        public int $openIncidents = 0,
        public ?string $lastDeployAt = null,
        public array $visitors = [],
    ) {}
}
