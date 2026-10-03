<?php

declare(strict_types=1);

namespace App\Data\Shell;

final readonly class NavLink
{
    /**
     * Create a new NavLink instance.
     *
     * A link in the app's navigation. The app marks the current one from its own address.
     *
     * @param  string  $label  The link text.
     * @param  string  $url  The page's path, such as `/projects/01h…/deploy`.
     * @param  string|null  $icon  A Signal icon name, for links that show one.
     * @param  string|null  $service  The service the link opens, for the service tabs.
     */
    public function __construct(
        public string $label,
        public string $url,
        public ?string $icon = null,
        public ?string $service = null,
    ) {}
}
