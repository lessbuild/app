<?php

namespace App\Core\Data\Projects;

final readonly class ProjectInfrastructureSnapshot
{
    /** @param list<ProjectInfrastructureNode> $nodes
     * @param  list<ProjectInfrastructureEdge>  $edges
     */
    public function __construct(
        public array $nodes = [],
        public array $edges = [],
        public bool $available = true,
        public bool $truncated = false,
    ) {}
}
