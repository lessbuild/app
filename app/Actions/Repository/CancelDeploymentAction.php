<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\Runner;
use RuntimeException;

class CancelDeploymentAction
{
    /**
     * Capture the deployment whose recorded remote process must be stopped.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     * @param  Runner|null  $runner  Optional SSH runner; null creates the default runner for this operation.
     */
    public function __construct(
        private readonly Build $build,
        private readonly ?Runner $runner = null,
    ) {}

    /**
     * Validate the saved process identity, stop its matching remote process group, collect its remaining log, and remove temporary deployment files.
     *
     * @return string|null Remaining deployment output, or null when the remote log is empty.
     *
     * @throws RuntimeException If the recorded process identity is unsafe or remote cancellation fails.
     */
    public function handle(): ?string
    {
        $processId = $this->build->remote_process_id;
        if ($processId !== null && (! is_int($processId) || $processId < 1)) {
            throw new RuntimeException('The deployment does not have a valid remote process identifier.');
        }
        $processPath = $this->build->remote_process_path;
        if (! is_string($processPath) || ! preg_match('#^/tmp/[a-z0-9-]+\.sh$#D', $processPath)) {
            throw new RuntimeException('The deployment does not have a valid remote script path.');
        }

        $logLimit = max(1, (int) config('lessbuild.deployment_log_max_characters'));
        $logFile = escapeshellarg("/tmp/lessbuild-deployment-{$this->build->id}.log");
        $uploadFile = escapeshellarg("/tmp/lessbuild-deployment-{$this->build->id}.upload.log");
        $scriptFile = escapeshellarg($processPath);
        $pidFile = escapeshellarg(substr($processPath, 0, -3).'.pid');
        $knownProcessId = escapeshellarg($processId === null ? '' : (string) $processId);
        $command = <<<BASH
        DEPLOYMENT_SCRIPT={$scriptFile}
        PID_FILE={$pidFile}
        PROCESS_ID={$knownProcessId}

        if [ -z "\$PROCESS_ID" ] && [ -r "\$PID_FILE" ]; then
            PROCESS_ID="$(sudo head -n 1 -- "\$PID_FILE")"
        fi

        case "\$PROCESS_ID" in
            '' ) ;;
            *[!0-9]* ) exit 2 ;;
        esac

        matches_deployment() {
            [ -r "/proc/\$PROCESS_ID/cmdline" ] && sudo tr '\\0' ' ' < "/proc/\$PROCESS_ID/cmdline" | grep -Fq -- "\$DEPLOYMENT_SCRIPT"
        }

        if [ -n "\$PROCESS_ID" ] && sudo kill -0 -- "\$PROCESS_ID" 2>/dev/null; then
            matches_deployment || exit 2
            sudo kill -TERM -- "-\$PROCESS_ID"

            for attempt in 1 2 3 4 5; do
                sudo kill -0 -- "\$PROCESS_ID" 2>/dev/null || break
                sleep 1
            done

            if sudo kill -0 -- "\$PROCESS_ID" 2>/dev/null; then
                matches_deployment || exit 2
                sudo kill -KILL -- "-\$PROCESS_ID"
            fi
        fi
        tail -c {$logLimit} -- {$logFile} 2>/dev/null || true
        sudo rm -f -- {$logFile} {$uploadFile} {$scriptFile} {$pidFile}
        BASH;

        $result = ($this->runner ?? new Runner)
            ->server($this->build->repository->website->server)
            ->create()
            ->execute($command);

        if (! $result->isSuccessful()) {
            throw new RuntimeException('The remote deployment process could not be stopped.');
        }

        return $result->getOutput() !== '' ? $result->getOutput() : null;
    }
}
