<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\RepositoryHookScript;
use App\Models\Build;

class RunBuildCommandsScript extends RepositoryHookScript
{
    public static string $title = 'Run custom build commands';

    public static string $description = 'Run repository-specific commands before activating the release';

    public static string $identifier = 'ran-custom-build-commands';

    /**
     * Read the custom build hook from the build's repository.
     *
     * @param  Build  $build  The build associated with the configured repository.
     * @return string|null The custom build shell commands, or null when unset.
     */
    protected function commands(Build $build): ?string
    {
        return $build->repository->build_commands;
    }

    /**
     * Locate the setup release directory for this repository hook.
     *
     * @param  Build  $build  The build whose website supplies the deployment slug.
     * @return string The absolute remote path ending in /setup.
     */
    protected function workingDirectory(Build $build): string
    {
        return "/var/www/{$build->repository->website->deployment_slug}/setup";
    }

    /**
     * Describe a disabled custom build hook for the script log.
     *
     * @return string The human-readable skip message for the custom build stage.
     */
    protected function disabledMessage(): string
    {
        return 'Custom build commands disabled';
    }

    /**
     * Describe an unsuccessful custom build hook for failure callbacks.
     *
     * @return string The human-readable failure message for the custom build stage.
     */
    protected function failureMessage(): string
    {
        return 'Custom build commands failed';
    }
}
