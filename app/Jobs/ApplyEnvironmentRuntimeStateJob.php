<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ApplyEnvironmentRuntimeStateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    /**
     * Capture the environment and requested sleep or wake state for deferred SSH execution.
     *
     * @param  int  $environmentId  Persisted environment identifier reloaded by the queue worker.
     * @param  bool  $hibernate  Whether to stop application units and enter maintenance mode instead of restoring configured replicas.
     */
    public function __construct(public readonly int $environmentId, public readonly bool $hibernate = false) {}

    /**
     * Coalesce queued instances of this job for the same environment.
     *
     * @return string The environment identifier used by Laravel's unique-job lock.
     */
    public function uniqueId(): string
    {
        return (string) $this->environmentId;
    }

    /**
     * Apply maintenance mode and replica unit state remotely, then persist hibernation timestamps; skip environments without an attached server and deployment slug.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        $environment = Environment::query()->with(['website.server', 'processes'])->find($this->environmentId);
        if (! $environment?->website?->server || ! $environment->website->deployment_slug) {
            return;
        }
        $slug = $environment->website->deployment_slug;
        $root = escapeshellarg('/var/www/'.$slug.'/current');
        $prefix = escapeshellarg('buildpusher-'.$slug.'-');
        $replicas = max($environment->minimum_replicas, min($environment->maximum_replicas, $environment->desired_replicas));
        $hibernate = $this->hibernate ? '1' : '0';
        $script = <<<BASH
        set -Eeuo pipefail
        ROOT={$root}
        PREFIX={$prefix}
        HIBERNATE={$hibernate}
        REPLICAS={$replicas}
        if [ "\$HIBERNATE" = 1 ]; then
            [ -f "\$ROOT/artisan" ] && sudo -u www-data php "\$ROOT/artisan" down --retry=60 --refresh=60 || true
        else
            [ -f "\$ROOT/artisan" ] && sudo -u www-data php "\$ROOT/artisan" up || true
        fi
        shopt -s nullglob
        for unit_file in /etc/systemd/system/"\$PREFIX"*.service; do
            unit="\$(basename "\$unit_file")"
            replica="\${unit%.service}"; replica="\${replica##*-}"
            if [ "\$HIBERNATE" = 1 ] || ! [[ "\$replica" =~ ^[0-9]+$ ]] || [ "\$replica" -gt "\$REPLICAS" ]; then
                systemctl stop "\$unit" || true
            else
                systemctl enable --now "\$unit"
            fi
        done
        BASH;
        $result = $runner->server($environment->website->server)->create()->execute($script);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput()) ?: 'Unable to apply environment runtime state.');
        }
        $environment->forceFill([
            'hibernated_at' => $this->hibernate ? now() : null,
            'last_activity_at' => $this->hibernate ? $environment->last_activity_at : now(),
        ])->save();
    }
}
