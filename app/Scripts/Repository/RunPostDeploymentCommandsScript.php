<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\RepositoryHookScript;
use App\Models\Build;

class RunPostDeploymentCommandsScript extends RepositoryHookScript
{
    public static string $title = 'Run post-deployment commands';

    public static string $description = 'Run repository-specific commands after activating the release';

    public static string $identifier = 'ran-post-deployment-commands';

    protected function commands(Build $build): ?string
    {
        return $build->repository->post_deployment_commands;
    }

    protected function workingDirectory(Build $build): string
    {
        return "/var/www/{$build->repository->website->deployment_slug}/current";
    }

    protected function disabledMessage(): string
    {
        return 'Post-deployment commands disabled';
    }

    protected function failureMessage(): string
    {
        return 'Post-deployment commands failed';
    }
}
