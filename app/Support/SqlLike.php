<?php

namespace App\Support;

final class SqlLike
{
    /**
     * Escape literal search characters for a contains expression using an exclamation-mark SQL escape.
     *
     * @param  string  $value  The literal substring to match, including any percent, underscore or exclamation characters.
     * @return string The escaped substring wrapped in percent wildcards; the query must specify ESCAPE '!'.
     */
    public static function contains(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
