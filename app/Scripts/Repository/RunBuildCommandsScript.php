<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\RepositoryHookScript;
use App\Models\Build;

class RunBuildCommandsScript extends RepositoryHookScript
{
    public static string $title = 'Run custom build commands';

    public static string $description = 'Run repository-specific commands before activating the release';

    public static string $identifier = 'ran-custom-build-commands';

    protected function commands(Build $build): ?string
    {
        return $build->repository->build_commands;
    }

    protected function workingDirectory(Build $build): string
    {
        return "/var/www/{$build->repository->website->deployment_slug}/setup";
    }

    protected function disabledMessage(): string
    {
        return 'Custom build commands disabled';
    }

    protected function failureMessage(): string
    {
        return 'Custom build commands failed';
    }
}
