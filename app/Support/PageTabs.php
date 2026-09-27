<?php

declare(strict_types=1);

namespace App\Support;

/** Picks a page's current tab from `?tab=`, falling back to the first one the page offers. */
final class PageTabs
{
    /**
     * @param  array<string, mixed>  $tabs  key => label, in display order
     */
    public static function current(mixed $requested, array $tabs): string
    {
        return is_string($requested) && array_key_exists($requested, $tabs) ? $requested : (string) array_key_first($tabs);
    }
}
