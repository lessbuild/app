<?php

namespace App\Core\Data\Monitor;

use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Redacted Monitor administration values safe for Core-rendered views. */
final readonly class MonitorAdministrationSnapshot
{
    /** @param Collection<int, array<string, mixed>>|LengthAwarePaginator<int, array<string, mixed>> $items */
    public function __construct(
        public Collection|LengthAwarePaginator $items,
        public array $settings = [],
        public bool $available = true,
    ) {}
}
