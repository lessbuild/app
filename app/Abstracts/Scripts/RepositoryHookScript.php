<?php

namespace App\Abstracts\Scripts;

use App\Models\Build;

abstract class RepositoryHookScript extends BuildProvisioningScript
{
    /**
     * Select the repository hook body for this build.
     *
     * @param  Build  $build  The build whose repository defines the hook.
     * @return string|null Shell commands, or null when no hook is configured.
     */
    abstract protected function commands(Build $build): ?string;

    /**
     * Locate the release directory in which the hook must run.
     *
     * @param  Build  $build  The build whose deployment slug identifies the release root.
     * @return string The absolute remote working-directory path.
     */
    abstract protected function workingDirectory(Build $build): string;

    /**
     * Describe a skipped repository hook for the generated script.
     *
     * @return string A comment explaining that this hook is disabled.
     */
    abstract protected function disabledMessage(): string;

    /**
     * Describe hook execution failure for the deployment callback.
     *
     * @return string The failure message recorded when the hook exits unsuccessfully.
     */
    abstract protected function failureMessage(): string;

    /**
     * Render an isolated Bash hook with failure propagation and a progress callback.
     *
     * @param  int  $step  The stage to report after a successful or disabled hook.
     * @param  Build  $build  The build supplying commands, release path and callback identity.
     * @return string Shell source; null or blank hooks produce only a skip comment and progress callback.
     */
    public function script(int $step, Build $build): string
    {
        $commands = $this->commands($build);
        $progress = $this->progress($step, $build);
        if ($commands === null || trim($commands) === '') {
            $disabledMessage = $this->disabledMessage();

            return <<<SCRIPT

                # {$disabledMessage}
                {$progress}

            SCRIPT;
        }

        $workingDirectory = escapeshellarg($this->workingDirectory($build));
        $payload = escapeshellarg(base64_encode($commands));
        $failureMessage = escapeshellarg($this->failureMessage());

        return <<<SCRIPT

            cd -- {$workingDirectory}
            HOOK_STATUS=0
            bash -Eeuo pipefail <(printf '%s' {$payload} | base64 --decode) || HOOK_STATUS=$?
            if [ "\$HOOK_STATUS" -ne 0 ]; then
                DEPLOYMENT_FAILURE_MESSAGE={$failureMessage}
                false
            fi

            # Ping
            {$progress}

        SCRIPT;
    }
}
