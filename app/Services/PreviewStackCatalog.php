<?php

namespace App\Services;

use App\Data\PreviewStack;
use App\Models\Project;

class PreviewStackCatalog
{
    public function __construct(
        private readonly ApplicationTemplateCatalog $templates,
    ) {}

    /**
     * Resolve a preview stack from the project's curated application template.
     *
     * Templates without explicit preview resources intentionally produce an empty
     * resource list; unsupported runtimes are not silently given Laravel services.
     *
     * @param  Project  $project  The project whose validated preset selects the template.
     * @return PreviewStack The local declarations to persist before remote provisioning.
     */
    public function for(Project $project): PreviewStack
    {
        $template = $this->templates->for($project->preset ?: 'laravel');

        return new PreviewStack(
            processes: $template->processes,
            resources: $template->previewResources,
            initialization: $template->initialization,
        );
    }
}
