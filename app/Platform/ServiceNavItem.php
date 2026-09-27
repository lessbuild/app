<?php

declare(strict_types=1);

namespace App\Platform;

final readonly class ServiceNavItem
{
    /**
     * One link in a service's section of the project sidebar.
     *
     * @param  string  $label  The link text.
     * @param  string  $url  Where the link goes, already resolved for the project.
     * @param  string  $activePattern  Route name pattern that marks this item current, e.g. `projects.services.show`; `|` separates alternatives.
     */
    public function __construct(
        public string $label,
        public string $url,
        /** Route name pattern that marks this item current, e.g. `projects.services.show`. */
        public string $activePattern,
    ) {}
}
