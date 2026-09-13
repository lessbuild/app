<?php

namespace App\Services;

use App\Data\PreviewStack;
use App\Models\Project;

class PreviewStackCatalog
{
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
        $template = config('application-templates.'.($project->preset ?: 'laravel'), []);

        return new PreviewStack(
            processes: is_array($template['processes'] ?? null) ? $template['processes'] : [],
            resources: is_array($template['preview_resources'] ?? null) ? $template['preview_resources'] : [],
        );
    }
}
