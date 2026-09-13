<?php

namespace App\Scripts\Repository;

use App\Models\Build;

class PreviewInitializationScript
{
    /**
     * Render the one-time initialization hook without changing the deployment stage count.
     *
     * The command is encoded before it enters the shell source. The marker is written only
     * after a successful command, so a lost process may safely cause an at-least-once retry.
     *
     * @param  Build  $build  Build carrying the encrypted preview initialization snapshot.
     * @return string Shell source for the optional initialization phase.
     */
    public function render(Build $build): string
    {
        $initialization = $build->environment_payload['preview_initialization'] ?? null;
        if (! is_array($initialization) || ! is_string($initialization['command'] ?? null) || trim($initialization['command']) === '') {
            return <<<'SCRIPT'

            # Preview initialization not configured

            SCRIPT;
        }

        $command = escapeshellarg(base64_encode($initialization['command']));
        $attempt = max(1, (int) ($initialization['attempt'] ?? 1));
        $slug = $build->repository->website->deployment_slug;
        $marker = escapeshellarg("/var/www/{$slug}/shared/.buildpusher-preview-initialized");
        $workingDirectory = escapeshellarg("/var/www/{$slug}/current");

        return <<<SCRIPT

            PREVIEW_INITIALIZATION_MARKER={$marker}
            if [ -f "\$PREVIEW_INITIALIZATION_MARKER" ]; then
                echo "Preview initialization already completed"
            else
                cd -- {$workingDirectory}
                PREVIEW_INITIALIZATION_STATUS=0
                bash -Eeuo pipefail <(printf '%s' {$command} | base64 --decode) || PREVIEW_INITIALIZATION_STATUS=\$?
                if [ "\$PREVIEW_INITIALIZATION_STATUS" -ne 0 ]; then
                    DEPLOYMENT_FAILURE_MESSAGE='Preview initialization failed'
                    false
                fi
                mkdir -p -- "\$(dirname -- \"\$PREVIEW_INITIALIZATION_MARKER\")"
                printf '%s\\n' 'attempt={$attempt}' > "\$PREVIEW_INITIALIZATION_MARKER"
            fi

        SCRIPT;
    }
}
