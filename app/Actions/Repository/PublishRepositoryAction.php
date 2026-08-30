<?php

namespace App\Actions\Repository;

use App\Abstracts\Publishable;
use App\Models\Build;
use App\Models\Repository;
use App\Services\ProvisioningCallbackUrl;
use App\Services\ProvisioningScriptRenderer;
use App\Services\RepositoryDeploymentPlan;
use App\Services\Runner;

class PublishRepositoryAction extends Publishable
{
    private Repository $repository;

    private Build $build;

    private ProvisioningScriptRenderer $renderer;

    private RepositoryDeploymentPlan $plan;

    /**
     * Publish Repository Action constructor
     *
     *
     * @throws \Exception
     */
    public function __construct(
        Build $build,
        ?Runner $runner = null,
        ?ProvisioningScriptRenderer $renderer = null,
        ?RepositoryDeploymentPlan $plan = null,
    ) {
        $repository = $build->repository;
        parent::__construct($repository->website->server, $runner);

        $this->repository = $repository;
        $this->build = $build;
        $this->renderer = $renderer ?? app(ProvisioningScriptRenderer::class);
        $this->plan = $plan ?? app(RepositoryDeploymentPlan::class);
    }

    /**
     * @throws \Exception
     */
    /**
     * @return array{id: int, path: string}
     */
    public function handle(): array
    {
        $failureCallback = ProvisioningCallbackUrl::buildFailure($this->build);
        $logCallback = ProvisioningCallbackUrl::buildLog($this->build);
        $logFile = escapeshellarg("/tmp/lessbuild-deployment-{$this->build->id}.log");
        $logUploadFile = escapeshellarg("/tmp/lessbuild-deployment-{$this->build->id}.upload.log");
        $logLimit = max(1, (int) config('lessbuild.deployment_log_max_characters'));
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail

        LOG_FILE={$logFile}
        LOG_UPLOAD_FILE={$logUploadFile}

        upload_deployment_log() {
            tail -c {$logLimit} -- "\$LOG_FILE" > "\$LOG_UPLOAD_FILE"
            curl --silent --show-error --retry 2 \
                --data-urlencode "log@\$LOG_UPLOAD_FILE" \
                "{$logCallback}" || true
        }

        deployment_failed() {
            exit_code=\$?
            trap - ERR
            upload_deployment_log
            curl --silent --show-error \
                --data "exit_code=\$exit_code&message=Remote deployment script failed" \
                "{$failureCallback}" || true
            rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
            exit "\$exit_code"
        }

        trap deployment_failed ERR
        : > "\$LOG_FILE"
        exec > "\$LOG_FILE" 2>&1

        SCRIPT;

        $this->script .= $this->renderer->build($this->build, $this->plan->scripts());

        $this->script .= <<<'SCRIPT'

        upload_deployment_log
        rm -f -- "$LOG_FILE" "$LOG_UPLOAD_FILE"

        SCRIPT;

        $this->makeScriptFile($this->repository->name);

        $this->upload();

        $output = trim($this->run());
        if (! ctype_digit($output) || (int) $output < 1) {
            throw new \RuntimeException('The remote deployment started without returning a valid process identifier.');
        }

        return [
            'id' => (int) $output,
            'path' => "/tmp/{$this->fileName}.sh",
        ];
    }
}
