<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Build;
use App\Services\Infrastructure\ServerShell;
use RuntimeException;

/** Stops a running deployment script, and switches a website's `current` to a retained release (ported from Deployer). */
class RemoteDeployments
{
    /**
     * Controls deploys running on servers.
     *
     * @param  ServerShell  $shell  Runs commands on the website's server.
     */
    public function __construct(private readonly ServerShell $shell) {}

    /**
     * Kill the script's process group (only if it's still that script) and return the log it had written.
     *
     * @param  Build  $build
     * @return string|null
     */
    public function stop(Build $build): ?string
    {
        $path = $build->remote_process_path;
        if (! is_string($path) || preg_match('#\A/tmp/[a-z0-9-]+\.sh\z#D', $path) !== 1) {
            throw new RuntimeException('The deployment has no valid remote script path.');
        }
        $limit = max(1, (int) config('deploy.deployment_log_max_characters'));
        $log = escapeshellarg("/tmp/lessbuild-deployment-{$build->id}.log");
        $upload = escapeshellarg("/tmp/lessbuild-deployment-{$build->id}.upload.log");
        $script = escapeshellarg($path);
        $pidFile = escapeshellarg(substr($path, 0, -3).'.pid');
        $pid = escapeshellarg((string) ($build->remote_process_id ?? ''));
        $command = <<<BASH
        DEPLOYMENT_SCRIPT={$script}
        PID_FILE={$pidFile}
        PROCESS_ID={$pid}
        if [ -z "\$PROCESS_ID" ] && [ -r "\$PID_FILE" ]; then PROCESS_ID="$(sudo head -n 1 -- "\$PID_FILE")"; fi
        case "\$PROCESS_ID" in '' ) ;; *[!0-9]* ) exit 2 ;; esac
        matches_deployment() { [ -r "/proc/\$PROCESS_ID/cmdline" ] && sudo tr '\\0' ' ' < "/proc/\$PROCESS_ID/cmdline" | grep -Fq -- "\$DEPLOYMENT_SCRIPT"; }
        if [ -n "\$PROCESS_ID" ] && sudo kill -0 -- "\$PROCESS_ID" 2>/dev/null; then
            matches_deployment || exit 2
            sudo kill -TERM -- "-\$PROCESS_ID"
            for attempt in 1 2 3 4 5; do sudo kill -0 -- "\$PROCESS_ID" 2>/dev/null || break; sleep 1; done
            if sudo kill -0 -- "\$PROCESS_ID" 2>/dev/null; then matches_deployment || exit 2; sudo kill -KILL -- "-\$PROCESS_ID"; fi
        fi
        tail -c {$limit} -- {$log} 2>/dev/null || true
        sudo rm -f -- {$log} {$upload} {$script} {$pidFile}
        BASH;
        $server = $build->website->server ?? throw new RuntimeException('The website has no server.');
        $result = $this->shell->run($server, $command);
        if (! $result->successful()) {
            throw new RuntimeException('The deployment couldn’t be stopped on the server.');
        }

        return $result->output === '' ? null : $result->output;
    }

    /**
     * Point `current` at the build's retained release, reload PHP-FPM, and put the previous one back if the health check fails.
     *
     * @param  Build  $build
     * @return string
     */
    public function activate(Build $build): string
    {
        $website = $build->website;
        $root = "/var/www/{$website->deployment_slug}";
        if (! is_string($build->release_name) || preg_match('/\A[a-zA-Z0-9._-]+\z/D', $build->release_name) !== 1 || $build->release_path !== "{$root}/releases/{$build->release_name}") {
            throw new RuntimeException('The retained release is invalid.');
        }
        $fpm = 'systemctl reload '.escapeshellarg('php'.config('deploy.default_php_version').'-fpm');
        $health = $website->health_check_enabled
            ? 'if ! curl --fail --silent --show-error --location --connect-timeout 5 --max-time 15 --retry 5 --retry-delay 2 --retry-all-errors --output /dev/null '.escapeshellarg("https://{$website->url}{$website->health_check_path}")."; then\n"
                ."    if [ -n \"\$PREVIOUS_PATH\" ] && [ -d \"\$PREVIOUS_PATH\" ]; then ln -sfn -- \"\$PREVIOUS_PATH\" \"\$DEPLOY_ROOT/current.rollback\"; mv -Tf -- \"\$DEPLOY_ROOT/current.rollback\" \"\$DEPLOY_ROOT/current\"; fi\n"
                ."    echo 'The health check failed; the previous release is live again.' >&2\n    exit 1\nfi"
            : '';
        $deployRoot = escapeshellarg($root);
        $target = escapeshellarg($build->release_path);
        $command = <<<BASH
        set -Eeuo pipefail
        DEPLOY_ROOT={$deployRoot}
        TARGET_PATH={$target}
        [ -d "\$TARGET_PATH" ] || { echo 'That release is no longer on the server.' >&2; exit 2; }
        PREVIOUS_PATH="$(readlink -f -- "\$DEPLOY_ROOT/current" 2>/dev/null || true)"
        ln -sfn -- "\$TARGET_PATH" "\$DEPLOY_ROOT/current.next"
        mv -Tf -- "\$DEPLOY_ROOT/current.next" "\$DEPLOY_ROOT/current"
        {$fpm} || true
        {$health}
        printf 'Activated retained release: %s\n' "\$TARGET_PATH"
        BASH;
        $server = $website->server ?? throw new RuntimeException('The website has no server.');
        $result = $this->shell->run($server, $command);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput ?: $result->output) ?: 'The release couldn’t be activated.');
        }

        return trim($result->output);
    }
}
