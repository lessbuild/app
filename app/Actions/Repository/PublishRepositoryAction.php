<?php

namespace App\Actions\Repository;

use App\Abstracts\Publishable;
use App\Models\Build;
use App\Models\Repository;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\SymlinkScript;
use App\Services\ProvisioningCallbackUrl;
use App\Services\Runner;

class PublishRepositoryAction extends Publishable
{
    // Scripts to run
    public array $commands = [
        CloneRepositoryScript::class,
        CheckoutRepositoryScript::class,
        InstallDependenciesScript::class,
        ActivateReleaseScript::class,
        SymlinkScript::class,
        ArtisanCommandsScript::class,
        PurgeOldReleasesScript::class,
    ];

    private Repository $repository;

    private Build $build;

    /**
     * Publish Repository Action constructor
     *
     *
     * @throws \Exception
     */
    public function __construct(Build $build, ?Runner $runner = null)
    {
        $repository = $build->repository;
        parent::__construct($repository->website->server, $runner);

        $this->repository = $repository;
        $this->build = $build;
    }

    /**
     * @throws \Exception
     */
    public function handle(): void
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

        foreach ($this->commands as $key => $command) {
            $this->script .= app($command)->script(($key + 1), $this->build);
        }

        $this->script .= <<<'SCRIPT'

        upload_deployment_log
        rm -f -- "$LOG_FILE" "$LOG_UPLOAD_FILE"

        SCRIPT;

        $this->makeScriptFile($this->repository->name);

        $this->upload();

        $this->run();
    }
}
