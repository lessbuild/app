<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Project;

final class VerifiedHostnamesQuery
{
    /**
     * Get the project's verified hostnames, in ASCII.
     *
     * @param  Project  $project
     * @return list<string> the project's verified hostnames (ASCII)
     */
    public function handle(Project $project): array
    {
        return array_values($project->domains()->whereNotNull('verified_at')->pluck('hostname')->map(fn ($hostname): string => (string) $hostname)->all());
    }
}
