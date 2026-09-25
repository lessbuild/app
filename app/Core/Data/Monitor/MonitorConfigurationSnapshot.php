<?php

namespace App\Core\Data\Monitor;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Bounded, redacted values for mapped Monitor application configuration. */
final readonly class MonitorConfigurationSnapshot
{
    /**
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $applications
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $environments
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $checks
     */
    public function __construct(
        public LengthAwarePaginator $applications,
        public LengthAwarePaginator $environments,
        public LengthAwarePaginator $checks,
    ) {}
}
