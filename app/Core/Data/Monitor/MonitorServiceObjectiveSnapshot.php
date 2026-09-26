<?php

namespace App\Core\Data\Monitor;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Core-safe service objective fields and mapped environment choices from one Monitor workspace. */
final readonly class MonitorServiceObjectiveSnapshot
{
    /**
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $items
     * @param  list<array{reference: string, label: string}>  $environments
     */
    public function __construct(
        public LengthAwarePaginator $items,
        public array $environments,
        public bool $canManage,
    ) {}
}
