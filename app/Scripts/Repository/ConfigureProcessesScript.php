<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;
use Illuminate\Support\Str;

class ConfigureProcessesScript extends BuildProvisioningScript
{
    public static string $title = 'Configure workers and scheduler';

    public static string $description = 'Install and restart environment process definitions against the active release';

    public static string $identifier = 'configured-processes';

    public function script(int $step, Build $build): string
    {
        $slug = $build->repository->website->deployment_slug;
        $root = escapeshellarg("/var/www/{$slug}");
        $prefix = 'buildpusher-'.$slug;
        $desired = [];
        $active = [];
        $install = '';
        $runtime = $build->environment_payload['runtime'] ?? [];
        $maximumReplicas = max(1, min(20, (int) ($runtime['maximum_replicas'] ?? 1)));
        $desiredReplicas = max(1, min($maximumReplicas, (int) ($runtime['desired_replicas'] ?? 1)));
        $rolling = ($runtime['deployment_strategy'] ?? 'blue_green') === 'rolling';
        $rollingPause = max(0, min(30, (int) ($runtime['rolling_pause_seconds'] ?? 2)));
        foreach (($build->environment_payload['processes'] ?? []) as $process) {
            $name = Str::slug((string) ($process['name'] ?? ''));
            if ($name === '' || ! in_array($process['type'] ?? null, ['worker', 'scheduler'], true)) {
                continue;
            }
            $configuredReplicas = max(1, min(20, (int) ($process['replicas'] ?? 1)));
            $restartPolicy = in_array($process['restart_policy'] ?? null, ['always', 'on-failure', 'no'], true) ? $process['restart_policy'] : 'always';
            $restartDelay = max(0, min(300, (int) ($process['restart_delay_seconds'] ?? 5)));
            $replicas = ($process['type'] ?? null) === 'scheduler' ? 1 : max($configuredReplicas, $maximumReplicas);
            $script = "#!/bin/bash\nset -e\ncd /var/www/{$slug}/current\nexec ".($process['command'] ?? 'php artisan queue:work')."\n";
            $encodedScript = escapeshellarg(base64_encode($script));
            for ($replica = 1; $replica <= $replicas; $replica++) {
                $unit = "{$prefix}-{$name}-{$replica}.service";
                $desired[] = $unit;
                if (($process['type'] ?? null) === 'scheduler' || $replica <= $desiredReplicas) {
                    $active[] = $unit;
                }
                $unitConfig = "[Unit]\nDescription=BuildPusher {$name} {$replica}\nAfter=network.target\n\n[Service]\nType=simple\nUser=www-data\nGroup=www-data\nExecStart=/var/www/{$slug}/shared/processes/{$name}.sh\nRestart={$restartPolicy}\nRestartSec={$restartDelay}\nTimeoutStopSec=90\n\n[Install]\nWantedBy=multi-user.target\n";
                $encodedUnit = escapeshellarg(base64_encode($unitConfig));
                $unitPath = escapeshellarg('/etc/systemd/system/'.$unit);
                $install .= "printf '%s' {$encodedUnit} | base64 --decode > {$unitPath}\n";
            }
            $scriptPath = escapeshellarg("/var/www/{$slug}/shared/processes/{$name}.sh");
            $install .= "printf '%s' {$encodedScript} | base64 --decode > {$scriptPath}\nchmod 750 {$scriptPath}\nchown root:www-data {$scriptPath}\n";
        }
        $manifest = escapeshellarg(implode("\n", $desired).($desired === [] ? '' : "\n"));
        $activeManifest = escapeshellarg(implode("\n", $active).($active === [] ? '' : "\n"));
        $prepareExisting = $rolling
            ? <<<'SCRIPT'
        OLD_MANIFEST="$PROCESS_DIR/units.previous"
        if [ -f "$MANIFEST" ]; then cp -- "$MANIFEST" "$OLD_MANIFEST"; else : > "$OLD_MANIFEST"; fi
        SCRIPT
            : <<<SCRIPT
        if [ -f "\$MANIFEST" ]; then
            while IFS= read -r old_unit; do
                case "\$old_unit" in {$prefix}-*.service) systemctl disable --now "\$old_unit" 2>/dev/null || true; rm -f -- "/etc/systemd/system/\$old_unit" ;; esac
            done < "\$MANIFEST"
        fi
        SCRIPT;
        $activateUnit = $rolling
            ? "systemctl enable \"\$unit\" && systemctl restart \"\$unit\"\nsleep {$rollingPause}"
            : 'systemctl enable --now "$unit" && systemctl restart "$unit"';
        $cleanupExisting = $rolling ? <<<SCRIPT
        while IFS= read -r old_unit; do
            [ -n "\$old_unit" ] || continue
            if ! grep -Fxq -- "\$old_unit" "\$MANIFEST"; then
                case "\$old_unit" in {$prefix}-*.service) systemctl disable --now "\$old_unit" 2>/dev/null || true; rm -f -- "/etc/systemd/system/\$old_unit" ;; esac
            fi
        done < "\$OLD_MANIFEST"
        rm -f -- "\$OLD_MANIFEST"
        SCRIPT : '';
        $progress = $this->progress($step, $build);

        return <<<SCRIPT
        DEPLOY_ROOT={$root}
        PROCESS_DIR="\$DEPLOY_ROOT/shared/processes"
        MANIFEST="\$PROCESS_DIR/units"
        ACTIVE_MANIFEST="\$PROCESS_DIR/active-units"
        install -d -o root -g www-data -m 750 -- "\$PROCESS_DIR"
        {$prepareExisting}
        {$install}
        printf '%s' {$manifest} > "\$MANIFEST"
        printf '%s' {$activeManifest} > "\$ACTIVE_MANIFEST"
        systemctl daemon-reload
        while IFS= read -r unit; do
            [ -n "\$unit" ] || continue
            if grep -Fxq -- "\$unit" "\$ACTIVE_MANIFEST"; then
                {$activateUnit}
            else
                systemctl disable --now "\$unit" 2>/dev/null || true
            fi
        done < "\$MANIFEST"
        {$cleanupExisting}
        {$progress}
        SCRIPT;
    }
}
