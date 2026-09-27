<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Repository;

/**
 * What a build deploys with, captured when it's queued so a later settings change doesn't alter a queued or retried build:
 * the website's `.env` and the repository subdirectory. Deploy's environments (part 3) add their variables, processes,
 * resources and runtime here.
 */
final class BuildPayload
{
    /** @return array<string, mixed> */
    public function for(Repository $repository): array
    {
        return ['base_environment' => (string) $repository->website->env_file, 'repository_root' => $repository->deploymentRoot()];
    }
}
