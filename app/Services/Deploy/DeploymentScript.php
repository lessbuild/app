<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\Deploy\BuildScript;
use App\Models\Build;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use LogicException;

/**
 * The whole deployment script for a build: logging to a file that is uploaded every five seconds, the plan's stages,
 * and a failure trap that restores the previous release and reports the exit code (ported from Deployer's
 * PublishRepositoryAction).
 */
final class DeploymentScript
{
    /**
     * Renders the whole deploy script.
     *
     * @param  RepositoryDeploymentPlan  $plan  The stages in order.
     */
    public function __construct(private readonly RepositoryDeploymentPlan $plan) {}

    /**
     * The bash script for a build: output goes to a log uploaded every five seconds, each stage runs in order, and any
     * failure restores the previous release, uploads the log and reports the exit code.
     *
     * @param  Build  $build
     * @return string
     */
    public function render(Build $build): string
    {
        $failure = ProvisioningCallbackUrl::buildFailure($build);
        $logCallback = ProvisioningCallbackUrl::buildLog($build);
        $logFile = escapeshellarg("/tmp/lessbuild-deployment-{$build->id}.log");
        $uploadFile = escapeshellarg("/tmp/lessbuild-deployment-{$build->id}.upload.log");
        $limit = max(1, (int) config('deploy.deployment_log_max_characters'));
        $units = escapeshellarg('buildpusher-'.$build->website->deployment_slug.'-');
        $fpm = escapeshellarg('php'.config('deploy.default_php_version').'-fpm');
        $stages = '';
        foreach ($this->plan->scripts() as $index => $class) {
            $script = app($class);
            if (! $script instanceof BuildScript) {
                throw new LogicException("{$class} must implement BuildScript.");
            }
            $stages .= $script->script($index + 1, $build)."\n";
        }

        return <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail
        LOG_FILE={$logFile}
        LOG_UPLOAD_FILE={$uploadFile}
        DEPLOYMENT_FAILURE_MESSAGE="Remote deployment script failed"
        upload_deployment_log() {
            tail -c {$limit} -- "\$LOG_FILE" > "\$LOG_UPLOAD_FILE"
            curl --silent --show-error --retry 2 --data-urlencode "log@\$LOG_UPLOAD_FILE" "{$logCallback}" || true
        }
        stream_deployment_log() {
            while sleep 5; do upload_deployment_log; done
        }
        stop_deployment_log_stream() {
            if [ -n "\${LOG_STREAM_PID:-}" ]; then
                kill "\$LOG_STREAM_PID" 2>/dev/null || true
                wait "\$LOG_STREAM_PID" 2>/dev/null || true
                LOG_STREAM_PID=""
            fi
        }
        restore_previous_release() {
            if [ -z "\${DEPLOY_ROOT:-}" ] || [ -z "\${PREVIOUS_RELEASE_PATH:-}" ] || [ ! -d "\$PREVIOUS_RELEASE_PATH" ]; then
                return 0
            fi
            current_target="$(readlink -f -- "\$DEPLOY_ROOT/current" 2>/dev/null || true)"
            if [ "\$current_target" = "\$PREVIOUS_RELEASE_PATH" ]; then
                return 0
            fi
            rollback_link="\$DEPLOY_ROOT/current.rollback"
            if ln -sfn -- "\$PREVIOUS_RELEASE_PATH" "\$rollback_link" && mv -Tf -- "\$rollback_link" "\$DEPLOY_ROOT/current"; then
                # PHP-FPM may keep the failed release in opcache after the symlink changes.
                systemctl reload {$fpm} 2>/dev/null || true
                echo "Restored previous release: \$PREVIOUS_RELEASE_PATH"
                unit_prefix={$units}
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
            curl --silent --show-error --data "exit_code=\$exit_code" --data-urlencode "message=\$DEPLOYMENT_FAILURE_MESSAGE" "{$failure}" || true
            rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
            exit "\$exit_code"
        }
        trap deployment_failed ERR
        : > "\$LOG_FILE"
        exec > "\$LOG_FILE" 2>&1
        stream_deployment_log &
        LOG_STREAM_PID=\$!
        {$stages}
        stop_deployment_log_stream
        upload_deployment_log
        rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"

        SCRIPT;
    }
}
