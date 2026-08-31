<?php

namespace App\Abstracts\Scripts;

use App\Models\Build;

abstract class RepositoryHookScript extends BuildProvisioningScript
{
    abstract protected function commands(Build $build): ?string;

    abstract protected function workingDirectory(Build $build): string;

    abstract protected function disabledMessage(): string;

    abstract protected function failureMessage(): string;

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
