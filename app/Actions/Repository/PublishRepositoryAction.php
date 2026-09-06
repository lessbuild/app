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
        $processUnitPrefix = escapeshellarg('buildpusher-'.$this->repository->website->deployment_slug.'-');
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail

        LOG_FILE={$logFile}
        LOG_UPLOAD_FILE={$logUploadFile}
        DEPLOYMENT_FAILURE_MESSAGE="Remote deployment script failed"

        upload_deployment_log() {
            tail -c {$logLimit} -- "\$LOG_FILE" > "\$LOG_UPLOAD_FILE"
            curl --silent --show-error --retry 2 \
                --data-urlencode "log@\$LOG_UPLOAD_FILE" \
                "{$logCallback}" || true
        }

        stream_deployment_log() {
            while sleep 5; do
                upload_deployment_log
            done
        }

        stop_deployment_log_stream() {
            if [ -n "\${LOG_STREAM_PID:-}" ]; then
                kill "\$LOG_STREAM_PID" 2>/dev/null || true
                wait "\$LOG_STREAM_PID" 2>/dev/null || true
                LOG_STREAM_PID=""
            fi
        }

        restore_previous_release() {
            if [ -z "\${DEPLOY_ROOT:-}" ] \
                || [ -z "\${PREVIOUS_RELEASE_PATH:-}" ] \
                || [ ! -d "\$PREVIOUS_RELEASE_PATH" ]; then
                return 0
            fi

            current_target="$(readlink -f -- "\$DEPLOY_ROOT/current" 2>/dev/null || true)"
            if [ "\$current_target" = "\$PREVIOUS_RELEASE_PATH" ]; then
                return 0
            fi

            rollback_link="\$DEPLOY_ROOT/current.rollback"
            if ln -sfn -- "\$PREVIOUS_RELEASE_PATH" "\$rollback_link" \
                && mv -Tf -- "\$rollback_link" "\$DEPLOY_ROOT/current"; then
                echo "Restored previous release: \$PREVIOUS_RELEASE_PATH"
                unit_prefix={$processUnitPrefix}
                for unit_file in /etc/systemd/system/"\$unit_prefix"*.service; do
                    [ -f "\$unit_file" ] && systemctl restart "$(basename "\$unit_file")" || true
                done
            else
                echo "Unable to restore previous release: \$PREVIOUS_RELEASE_PATH"
            fi

            return 0
        }

        deployment_failed() {
            exit_code=\$?
            trap - ERR
            stop_deployment_log_stream
            restore_previous_release
            upload_deployment_log
            curl --silent --show-error \
                --data "exit_code=\$exit_code" \
                --data-urlencode "message=\$DEPLOYMENT_FAILURE_MESSAGE" \
                "{$failureCallback}" || true
            rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
            exit "\$exit_code"
        }

        trap deployment_failed ERR
        : > "\$LOG_FILE"
        exec > "\$LOG_FILE" 2>&1
        stream_deployment_log &
        LOG_STREAM_PID=\$!

        SCRIPT;

        $this->script .= $this->renderer->build($this->build, $this->plan->scripts());

        $this->script .= <<<'SCRIPT'

        stop_deployment_log_stream
        upload_deployment_log
        rm -f -- "$LOG_FILE" "$LOG_UPLOAD_FILE"

        SCRIPT;

        $this->makeScriptFile(
            $this->repository->name,
            "lessbuild-deployment-{$this->build->id}",
        );
        $remotePath = "/tmp/{$this->fileName}.sh";

        $this->upload();

        $output = trim($this->run());
        if (! ctype_digit($output) || (int) $output < 1) {
            throw new \RuntimeException('The remote deployment started without returning a valid process identifier.');
        }

        return [
            'id' => (int) $output,
            'path' => $remotePath,
        ];
    }
}
