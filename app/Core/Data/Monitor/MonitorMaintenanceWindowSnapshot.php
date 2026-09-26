<?php

namespace App\Core\Data\Monitor;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Core-safe maintenance-window fields from one exact Monitor workspace. */
final readonly class MonitorMaintenanceWindowSnapshot
{
    /** @param LengthAwarePaginator<int, array<string, mixed>> $items */
    public function __construct(
        public LengthAwarePaginator $items,
        public bool $canManage,
    ) {}
}
