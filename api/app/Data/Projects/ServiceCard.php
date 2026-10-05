<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ServiceCard
{
    /**
     * Create a new ServiceCard instance.
     *
     * One service on a project's overview.
     *
     * @param  string  $key  The service's key.
     * @param  string  $name  The service's name.
     * @param  string  $tagline  One line describing the service.
     * @param  string  $icon  The service's icon.
     * @param  bool  $enabled  Whether it's on in this project.
     * @param  bool  $canUse  The viewer may open this service's pages.
     * @param  bool  $canManage  The viewer may turn it on or off.
     * @param  string  $url  The project's page for the service, which forwards to the service's own pages once it's enabled.
     * @param  list<array{label: string, url: string}>  $sections  the service's pages in this project, for quick links
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $tagline,
        public string $icon,
        public bool $enabled,
        public bool $canUse,
        public bool $canManage,
        public string $url,
        public array $sections = [],
    ) {}
}
