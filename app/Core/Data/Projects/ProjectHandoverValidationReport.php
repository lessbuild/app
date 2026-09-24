<?php

namespace App\Core\Data\Projects;

final readonly class ProjectHandoverValidationReport
{
    /**
     * @param  array<string, array{access:bool,plan_available:?bool,limits_visible:bool,limits:array<string,int|null>}>  $products
     * @param  list<array{reference:string,state:string}>  $resources
     * @param  list<ProjectHandoverFinding>  $findings
     * @param  array{environments:int,resources:int,connections:int}  $counts
     */
    public function __construct(
        public string $status,
        public ?string $sourceProjectId,
        public array $counts,
        public array $products,
        public array $resources,
        public array $findings,
    ) {}

    /** @param list<ProjectHandoverFinding> $findings */
    public static function fromFindings(
        ?string $sourceProjectId,
        array $counts,
        array $products,
        array $resources,
        array $findings,
    ): self {
        $status = collect($findings)->contains(fn (ProjectHandoverFinding $finding): bool => $finding->severity === 'blocker')
            ? 'blocked'
            : (collect($findings)->contains(fn (ProjectHandoverFinding $finding): bool => $finding->severity === 'review')
                ? 'review'
                : 'ready');

        return new self($status, $sourceProjectId, $counts, $products, $resources, $findings);
    }
}
