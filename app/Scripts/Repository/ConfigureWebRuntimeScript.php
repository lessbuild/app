<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class ConfigureWebRuntimeScript extends BuildProvisioningScript
{
    public static string $title = 'Switch web runtime';

    public static string $description = 'Start the candidate runtime, verify it, and atomically route traffic to it';

    public static string $identifier = 'configured-web-runtime';

    /**
     * Render candidate startup, readiness checks and Caddy activation for the captured web runtime.
     *
     * @param  int  $step  The provisioning stage reported when these commands succeed.
     * @param  Build  $build  The build supplying the immutable environment snapshot and website identity.
     * @return string Shell source for the remote provisioning runner.
     */
    public function script(int $step, Build $build): string
    {
        $runtime = $build->environment_payload['runtime'] ?? [];
        $type = $runtime['type'] ?? 'php';
        $progress = $this->progress($step, $build);
        if (! in_array($type, ['node', 'python', 'docker'], true)) {
            return "# PHP remains served by Caddy and PHP-FPM\n{$progress}";
        }

        $website = $build->repository->website;
        $website->loadMissing('domains');
        $slug = $website->deployment_slug;
        $hostPort = 20000 + (($website->id * 997 + $build->id) % 30000);
        $containerPort = max(1, min(65535, (int) ($runtime['container_port'] ?? 3000)));
        $healthPath = str_starts_with((string) $website->health_check_path, '/') ? $website->health_check_path : '/';
        $startCommand = trim((string) ($runtime['start_command'] ?? ''));
        $encodedStartCommand = escapeshellarg(base64_encode($startCommand));
        $image = escapeshellarg("buildpusher/{$slug}:build-{$build->id}");
        $candidate = escapeshellarg("buildpusher-{$slug}-web-{$build->id}");
        $root = escapeshellarg("/var/www/{$slug}");
        $environmentPath = escapeshellarg("/var/www/{$slug}/.env");
        $manifestPath = escapeshellarg("/var/www/{$slug}/shared/web-runtime");
        $configPath = escapeshellarg("/etc/caddy/websites/{$slug}.conf");
        $health = escapeshellarg("http://127.0.0.1:{$hostPort}{$healthPath}");
        $unit = "buildpusher-{$slug}-web-{$build->id}.service";
        $unitPath = escapeshellarg("/etc/systemd/system/{$unit}");
        $runnerPath = escapeshellarg("/var/www/{$slug}/shared/web-{$build->id}.sh");
        $runner = "#!/bin/bash\nset -e\nset -a\n. /var/www/{$slug}/.env\nset +a\nexport PORT={$hostPort}\nexport HOST=127.0.0.1\ncd /var/www/{$slug}/current\nCOMMAND=\"\$(printf '%s' {$encodedStartCommand} | base64 --decode)\"\nexec /bin/bash -lc \"\$COMMAND\"\n";
        $encodedRunner = escapeshellarg(base64_encode($runner));
        $unitConfig = "[Unit]\nDescription=BuildPusher {$slug} web runtime {$build->id}\nAfter=network.target\n\n[Service]\nType=simple\nUser=www-data\nGroup=www-data\nExecStart=/var/www/{$slug}/shared/web-{$build->id}.sh\nRestart=always\nRestartSec=3\nTimeoutStopSec=30\n\n[Install]\nWantedBy=multi-user.target\n";
        $encodedUnit = escapeshellarg(base64_encode($unitConfig));
        $hostnames = $website->domains->where('type', 'alias')->pluck('hostname')->prepend($website->url)->unique()->implode(', ');
        $caddy = "{$hostnames} {\n    encode zstd gzip\n    reverse_proxy 127.0.0.1:{$hostPort}\n    log {\n        output file /var/log/caddy/{$slug}.access.log {\n            roll_size 20MiB\n            roll_keep 5\n            roll_keep_for 168h\n        }\n        format json\n    }\n}\n";
        foreach ($website->domains->where('type', 'redirect') as $domain) {
            $caddy .= "\n{$domain->hostname} {\n    redir ".rtrim((string) $domain->redirect_url, '/')."{uri} permanent\n}\n";
        }
        $encodedCaddy = escapeshellarg(base64_encode($caddy));

        $start = $type === 'docker'
            ? "docker run --detach --name {$candidate} --restart unless-stopped --env-file {$environmentPath} --publish 127.0.0.1:{$hostPort}:{$containerPort} {$image}\nCANDIDATE_KIND=container\nCANDIDATE_NAME={$candidate}"
            : "test -n {$encodedStartCommand}\nprintf '%s' {$encodedRunner} | base64 --decode > {$runnerPath}\nchmod 750 {$runnerPath}\nchown root:www-data {$runnerPath}\nprintf '%s' {$encodedUnit} | base64 --decode > {$unitPath}\nsystemctl daemon-reload\nsystemctl enable --now {$unit}\nCANDIDATE_KIND=service\nCANDIDATE_NAME=".escapeshellarg($unit);

        return <<<SCRIPT
        DEPLOY_ROOT={$root}
        RUNTIME_MANIFEST={$manifestPath}
        PREVIOUS_RUNTIME=""
        [ ! -f "\$RUNTIME_MANIFEST" ] || PREVIOUS_RUNTIME="$(cat "\$RUNTIME_MANIFEST")"
        {$start}
        cleanup_candidate() {
            if [ "\$CANDIDATE_KIND" = container ]; then docker rm --force "\$CANDIDATE_NAME" >/dev/null 2>&1 || true; else systemctl disable --now "\$CANDIDATE_NAME" >/dev/null 2>&1 || true; rm -f -- "/etc/systemd/system/\$CANDIDATE_NAME"; fi
        }
        trap cleanup_candidate EXIT
        READY=0
        for attempt in 1 2 3 4 5 6 7 8 9 10; do
            if curl --fail --silent --show-error --max-time 5 --header "Host: {$website->url}" --output /dev/null {$health}; then READY=1; break; fi
            sleep 2
        done
        if [ "\$READY" -ne 1 ]; then
            echo 'Candidate web runtime did not become healthy.'
            DEPLOYMENT_FAILURE_MESSAGE='Candidate web runtime did not become healthy'
            false
        fi
        printf '%s' {$encodedCaddy} | base64 --decode > {$configPath}
        caddy validate --config /etc/caddy/Caddyfile
        systemctl reload caddy
        printf '%s:%s\n' "\$CANDIDATE_KIND" "\$CANDIDATE_NAME" > "\$RUNTIME_MANIFEST"
        trap - EXIT
        if [ -n "\$PREVIOUS_RUNTIME" ] && [ "\$PREVIOUS_RUNTIME" != "\$CANDIDATE_KIND:\$CANDIDATE_NAME" ]; then
            PREVIOUS_KIND="$(printf '%s' "\$PREVIOUS_RUNTIME" | cut -d: -f1)"
            PREVIOUS_NAME="$(printf '%s' "\$PREVIOUS_RUNTIME" | cut -d: -f2-)"
            case "\$PREVIOUS_NAME" in buildpusher-{$slug}-web-*)
                if [ "\$PREVIOUS_KIND" = container ]; then docker rm --force "\$PREVIOUS_NAME" >/dev/null 2>&1 || true; else systemctl disable --now "\$PREVIOUS_NAME" >/dev/null 2>&1 || true; rm -f -- "/etc/systemd/system/\$PREVIOUS_NAME"; systemctl daemon-reload; fi
            esac
        fi
        {$progress}
        SCRIPT;
    }
}
