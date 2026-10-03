<?php

declare(strict_types=1);

namespace App\Platform\Search;

/** A case-insensitive "contains" pattern for LIKE, the same on PostgreSQL and SQLite. */
final class Like
{
    /**
     * Lowercase the term and escapes `\`, `%` and `_`, so what someone types is matched literally. Compare it against
     * a lowercased column.
     *
     * @param  string  $term
     * @return string
     */
    public static function contains(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
    }
}
