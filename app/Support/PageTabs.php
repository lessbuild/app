<?php

declare(strict_types=1);

namespace App\Support;

/** Picks a page's current tab from `?tab=`, falling back to the first one the page offers. */
final class PageTabs
{
    /**
     * The tab a page opens on: the `?tab=` value when it names one of the page's tabs, otherwise the first tab. Pages
     * render every panel and switch between them in the browser, so this only decides which panel is visible on first
     * load.
     *
     * @param  mixed  $requested
     * @param  array<string, mixed>  $tabs  key => label, in display order
     * @return string
     */
    public static function current(mixed $requested, array $tabs): string
    {
        return is_string($requested) && array_key_exists($requested, $tabs) ? $requested : (string) array_key_first($tabs);
    }
}
