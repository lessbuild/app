<?php

declare(strict_types=1);

namespace App\Http\View;

final readonly class NavLink
{
    /**
     * One link in the shell's navigation.
     *
     * @param  string  $label  The link text.
     * @param  string  $url  Where it goes.
     * @param  bool  $current  Whether it's the page being shown.
     * @param  ?string  $icon  An icon from the Signal sprite, for links that show one.
     */
    public function __construct(
        public string $label,
        public string $url,
        public bool $current = false,
        public ?string $icon = null,
    ) {}
}
