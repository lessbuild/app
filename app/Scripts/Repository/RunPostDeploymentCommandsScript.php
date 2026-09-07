<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\RepositoryHookScript;
use App\Models\Build;

class RunPostDeploymentCommandsScript extends RepositoryHookScript
{
    public static string $title = 'Run post-deployment commands';

    public static string $description = 'Run repository-specific commands after activating the release';

    public static string $identifier = 'ran-post-deployment-commands';

    /**
     * Read the post-deployment hook from the build's repository.
     *
     * @param  Build  $build  The build associated with the configured repository.
     * @return string|null The post-deployment shell commands, or null when unset.
     */
    protected function commands(Build $build): ?string
    {
        return $build->repository->post_deployment_commands;
    }

    /**
     * Locate the current release directory for this repository hook.
     *
     * @param  Build  $build  The build whose website supplies the deployment slug.
     * @return string The absolute remote path ending in /current.
     */
    protected function workingDirectory(Build $build): string
    {
        return "/var/www/{$build->repository->website->deployment_slug}/current";
    }

    /**
     * Describe a disabled post-deployment hook for the script log.
     *
     * @return string The human-readable skip message for the post-deployment stage.
     */
    protected function disabledMessage(): string
    {
        return 'Post-deployment commands disabled';
    }

    /**
     * Describe an unsuccessful post-deployment hook for failure callbacks.
     *
     * @return string The human-readable failure message for the post-deployment stage.
     */
    protected function failureMessage(): string
    {
        return 'Post-deployment commands failed';
    }
}
