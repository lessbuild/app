<?php

namespace App\Core\Data\Blueprints;

final readonly class BlueprintProductPreview
{
    /**
     * @param  list<string>  $changes  Safe descriptions of actual native changes.
     * @param  list<string>  $requirements  Remaining setup, secret supply, and verification steps.
     * @param  list<string>  $blockers  Safe messages preventing application.
     * @param  array<string, int|string|null>  $planImpact  Existing allowance and expected usage; never a subscription change.
     * @param  array<string, mixed>  $authority  Native identifiers/revisions for drift checks; not a view payload.
     */
    public function __construct(
        public array $changes = [],
        public array $requirements = [],
        public array $blockers = [],
        public array $planImpact = [],
        public array $authority = [],
    ) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}
