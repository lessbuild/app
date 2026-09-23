<?php

namespace App\Core\Services\Search;

final class WorkspaceSearchPattern
{
    public static function contains(string $query): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query).'%';
    }
}
