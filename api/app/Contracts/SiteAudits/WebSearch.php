<?php

declare(strict_types=1);

namespace App\Contracts\SiteAudits;

/** Searches the web, for finding a site's competitors. */
interface WebSearch
{
    /**
     * Determine whether searching is set up.
     *
     * @return bool
     */
    public function configured(): bool;

    /**
     * Search the web, returning nothing when searching isn't set up or fails.
     *
     * @param  string  $query
     * @param  int  $count
     * @return list<array{title: string, url: string, description: string}>
     */
    public function search(string $query, int $count = 10): array;
}
