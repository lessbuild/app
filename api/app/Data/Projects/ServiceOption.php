<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Platform\PlatformService;

final readonly class ServiceOption
{
    /**
     * Create a new ServiceOption instance.
     *
     * A service people can turn on for a project, as the new-project wizard and the project's settings offer it.
     *
     * @param  string  $key
     * @param  string  $name
     * @param  string  $tagline  One line on what it's for.
     * @param  string  $icon  A Signal icon name.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $tagline,
        public string $icon,
    ) {}

    /**
     * Describe a registered service.
     *
     * @param  PlatformService  $service
     * @return self
     */
    public static function from(PlatformService $service): self
    {
        return new self($service->key(), $service->name(), $service->tagline(), $service->icon());
    }
}
