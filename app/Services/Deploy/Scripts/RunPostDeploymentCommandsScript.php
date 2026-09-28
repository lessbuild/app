<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;

class RunPostDeploymentCommandsScript extends RepositoryHookScript
{
    public const TITLE = 'Run post-deployment commands';

    public const DESCRIPTION = 'Run repository-specific commands after activating the release';

    public const IDENTIFIER = 'ran-post-deployment-commands';

    /**
     * Runs the repository's post-deployment commands, preceded by preview initialisation. Tests can pass their own
     * initialisation script.
     */
    public function __construct(?PreviewInitializationScript $previewInitialization = null)
    {
        $this->previewInitialization = $previewInitialization ?? new PreviewInitializationScript;
    }

    /**
     * Runs a preview's one-time initialisation before the repository's own post-deployment commands.
     */
    private readonly PreviewInitializationScript $previewInitialization;

    /**
     * Keep preview initialization in the existing post-deployment stage.
     *
     * @param  int  $step  Existing deployment stage reported after both hooks succeed.
     * @param  Build  $build  Build supplying the optional initialization and repository hook.
     * @return string Shell source for initialization followed by the existing hook behavior.
     */
    public function script(int $step, Build $build): string
    {
        return $this->previewInitialization->render($build).parent::script($step, $build);
    }

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
     * @return string The absolute remote path for the repository service's active release.
     */
    protected function workingDirectory(Build $build): string
    {
        return $build->deploymentPath('current');
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
