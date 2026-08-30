<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\Runner;
use RuntimeException;

class CancelDeploymentAction
{
    public function __construct(
        private readonly Build $build,
        private readonly ?Runner $runner = null,
    ) {}

    public function handle(): void
    {
        $processId = $this->build->remote_process_id;
        if (! is_int($processId) || $processId < 1) {
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
        $command = <<<BASH
        DEPLOYMENT_SCRIPT={$scriptFile}
        matches_deployment() {
            [ -r /proc/{$processId}/cmdline ] && sudo tr '\\0' ' ' < /proc/{$processId}/cmdline | grep -Fq -- "\$DEPLOYMENT_SCRIPT"
        }

        if sudo kill -0 -- {$processId} 2>/dev/null; then
            matches_deployment || exit 2
            sudo kill -TERM -- -{$processId}

            for attempt in 1 2 3 4 5; do
                sudo kill -0 -- {$processId} 2>/dev/null || break
                sleep 1
            done

            if sudo kill -0 -- {$processId} 2>/dev/null; then
                matches_deployment || exit 2
                sudo kill -KILL -- -{$processId}
            fi
        fi
        tail -c {$logLimit} -- {$logFile} 2>/dev/null || true
        sudo rm -f -- {$logFile} {$uploadFile} {$scriptFile}
        BASH;

        $result = ($this->runner ?? new Runner)
            ->server($this->build->repository->website->server)
            ->create()
            ->execute($command);

        if (! $result->isSuccessful()) {
            throw new RuntimeException('The remote deployment process could not be stopped.');
        }

        if ($result->getOutput() !== '') {
            $this->build->logs()->updateOrCreate(
                ['type' => 'deployment'],
                ['log' => $result->getOutput()],
            );
        }
    }
}
