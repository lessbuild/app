<?php

namespace App\Core\Data\Status;

use Illuminate\Support\Collection;

/**
 * @param  Collection<int, array<string, mixed>>  $components
 * @param  Collection<int, object>  $incidents
 * @param  Collection<int, object>  $recentIncidents
 */
final readonly class CustomerStatusPage
{
    public function __construct(
        public string $product,
        public string $slug,
        public string $name,
        public string $workspaceName,
        public ?string $description,
        public string $overall,
        public string $overallLabel,
        public Collection $components,
        public Collection $incidents,
        public Collection $recentIncidents,
    ) {}
}
