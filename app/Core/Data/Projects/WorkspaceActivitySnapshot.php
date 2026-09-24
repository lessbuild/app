<?php

namespace App\Core\Data\Projects;

use Illuminate\Support\Collection;

/** @param Collection<int, ProjectWorkflowRun> $runs */
final readonly class WorkspaceActivitySnapshot
{
    public function __construct(
        public Collection $runs,
        public bool $available = true,
    ) {}
}
