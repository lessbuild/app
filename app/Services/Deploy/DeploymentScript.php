<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\Deploy\BuildScript;
use App\Models\Build;
use App\Services\Deploy\Scripts\CheckoutRepositoryScript;
use App\Services\Deploy\Scripts\CloneRepositoryScript;
use App\Services\Deploy\Scripts\InstallDependenciesScript;
use App\Services\Deploy\Scripts\RunBuildCommandsScript;
use App\Services\Deploy\Scripts\SyncEnvironmentScript;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use LogicException;

/**
 * The whole deployment script for a build: logging to a file that is uploaded every five seconds, the plan's stages,
 * and a failure trap that restores the previous release and reports the exit code (ported from Deployer's
 * PublishRepositoryAction). With a build server it comes in two parts: the build part clones, installs and builds
 * there and uploads the result to a storage bucket; the release part downloads it on the website's server and runs
 * the remaining stages. Both report the plan's stage numbers, so progress reads the same.
 */
final class DeploymentScript
{
    /**
     * Create a new DeploymentScript instance.
     *
     * Renders the whole deploy script.
     *
     * @param  RepositoryDeploymentPlan  $plan  The stages in order.
     */
    public function __construct(private readonly RepositoryDeploymentPlan $plan) {}

    /** The stages that run on a build server; the rest run on the website's server. */
    private const array BUILD_STAGES = [CloneRepositoryScript::class, CheckoutRepositoryScript::class, InstallDependenciesScript::class, RunBuildCommandsScript::class];

    /**
     * Render the bash script for a build that runs entirely on its website's server.
     *
     * @param  Build  $build
     * @return string
     */
    public function render(Build $build): string
    {
        return $this->wrap($build, $this->stages($build, fn (string $class): bool => true));
    }

    /**
     * Render the build part for a build server: clone, export the build variables (never the runtime ones), install
     * and build, then pack the release, upload it to the presigned URL and report it ready.
     *
     * @param  Build  $build
     * @param  string  $uploadUrl  A presigned PUT URL for the artifact.
     * @return string
     */
    public function renderBuild(Build $build, string $uploadUrl): string
    {
        $setup = escapeshellarg($build->deploymentPath('setup'));
        $artifact = escapeshellarg("/tmp/lessbuild-artifact-{$build->id}.tar.gz");
        $upload = escapeshellarg($uploadUrl);
        $ready = escapeshellarg(ProvisioningCallbackUrl::buildArtifact($build));
        $stages = $this->stages($build, fn (string $class): bool => in_array($class, self::BUILD_STAGES, true) || $class === SyncEnvironmentScript::class, true);

        return $this->wrap($build, <<<SCRIPT
        {$stages}
        echo "Packing the release for the website's server"
        tar --create --gzip --file {$artifact} --directory {$setup} .
        curl --fail --silent --show-error --retry 3 --upload-file {$artifact} {$upload}
        rm -f -- {$artifact}
        rm -rf -- {$setup}
        echo "Uploaded; the website's server takes it from here"
        # The full build log goes up before the release part starts, so the release part can carry it on.
        stop_deployment_log_stream
        upload_deployment_log
        curl --fail --silent --show-error --retry 2 --user-agent "deployer" --data "ready=1" {$ready}
        SCRIPT);
    }

    /**
     * Render the release part for the website's server: keep the build part's log, download and unpack the built
     * release, prepare the environment's resources, then run the stages after the build.
     *
     * @param  Build  $build
     * @param  string  $downloadUrl  A presigned GET URL for the artifact.
     * @return string
     */
    public function renderRelease(Build $build, string $downloadUrl): string
    {
        $setup = escapeshellarg($build->deploymentPath('setup'));
        $artifact = escapeshellarg("/tmp/lessbuild-artifact-{$build->id}.tar.gz");
        $download = escapeshellarg($downloadUrl);
        $buildLog = escapeshellarg(base64_encode((string) $build->log));
        $resources = app(ManagedResourceScript::class)->render($build->environment_payload['resources'] ?? []);
        $stages = $this->stages($build, fn (string $class): bool => ! in_array($class, self::BUILD_STAGES, true));

        return $this->wrap($build, <<<SCRIPT
        printf '%s' {$buildLog} | base64 --decode
        echo
        echo "Downloading the release built on the build server"
        rm -rf -- {$setup}
        install -d -m 755 -- {$setup}
        curl --fail --silent --show-error --retry 3 --output {$artifact} {$download}
        tar --extract --gzip --file {$artifact} --directory {$setup}
        rm -f -- {$artifact}
        {$resources}
        {$stages}
        SCRIPT);
    }

    /**
     * Render the chosen stages of the plan in order, each reporting its plan stage number. On a build server the
     * environment stage only exports the build variables, and dependencies install without preparing resources.
     *
     * @param  Build  $build
     * @param  callable(class-string): bool  $include
     * @param  bool  $buildServer
     * @return string
     */
    private function stages(Build $build, callable $include, bool $buildServer = false): string
    {
        $stages = '';
        foreach ($this->plan->scripts() as $index => $class) {
            if (! $include($class)) {
                continue;
            }
            $script = app($class);
            if (! $script instanceof BuildScript) {
                throw new LogicException("{$class} must implement BuildScript.");
            }
            if ($buildServer && $script instanceof SyncEnvironmentScript) {
                $variables = escapeshellarg(base64_encode($script->buildEnvironment($build)));
                $file = escapeshellarg("/tmp/lessbuild-build-{$build->id}.env");
                $stages .= "printf '%s' {$variables} | base64 --decode > {$file}\nchmod 600 {$file}\nset -a\n. {$file}\nset +a\nrm -f -- {$file}\n".$script->progress($index + 1, $build)."\n";

                continue;
            }
            if ($buildServer && $script instanceof InstallDependenciesScript) {
                $script = $script->onBuildServer();
            }
            $stages .= $script->script($index + 1, $build)."\n";
        }

        return $stages;
    }

    /**
     * Wrap stages in the logging and failure handling every deploy script has: output goes to a log uploaded every
     * five seconds, and any failure restores the previous release, uploads the log and reports the exit code.
     *
     * @param  Build  $build
     * @param  string  $stages
     * @return string
     */
    private function wrap(Build $build, string $stages): string
    {
        $failure = ProvisioningCallbackUrl::buildFailure($build);
        $logCallback = ProvisioningCallbackUrl::buildLog($build);
        $logFile = escapeshellarg("/tmp/lessbuild-deployment-{$build->id}.log");
        $uploadFile = escapeshellarg("/tmp/lessbuild-deployment-{$build->id}.upload.log");
        $limit = max(1, (int) config('deploy.deployment_log_max_characters'));
        $units = escapeshellarg('buildpusher-'.$build->website->deployment_slug.'-');
        $fpm = escapeshellarg('php'.$build->website->phpVersion().'-fpm');

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
