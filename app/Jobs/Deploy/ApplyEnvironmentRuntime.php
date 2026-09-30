<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Environment;
use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Brings an environment's websites to the state it should be in: running its desired worker replicas, or hibernating
 * (Laravel apps in maintenance mode, workers stopped). Only units the last deploy installed are touched, so raising the
 * maximum replicas still needs a deploy.
 */
final class ApplyEnvironmentRuntime implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * A busy server can refuse the connection, so it gets three tries.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 15;

    /**
     * Seconds a queued change stays the only one for its environment.
     *
     * @var int
     */
    public int $uniqueFor = 120;

    /**
     * Create a new ApplyEnvironmentRuntime instance.
     *
     * Applies one environment's running state.
     *
     * @param  string  $environmentId  The environment.
     * @param  bool  $hibernate  true to hibernate it, false to run it with its desired replicas.
     */
    public function __construct(public readonly string $environmentId, public readonly bool $hibernate) {}

    /**
     * Get the key that keeps one queued change per environment and state.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return $this->environmentId.($this->hibernate ? ':hibernate' : ':run');
    }

    /**
     * Apply the state on every website the environment deploys to, then record it: hibernated from now, or running
     * with activity now.
     *
     * @param  ServerShell  $shell
     * @return void
     */
    public function handle(ServerShell $shell): void
    {
        $environment = Environment::query()->find($this->environmentId);
        if ($environment === null) {
            return;
        }
        $replicas = max($environment->minimum_replicas, min($environment->maximum_replicas, $environment->desired_replicas));
        foreach ($environment->deployedWebsites() as $website) {
            if ($website->server === null || $website->server->provisioning_status !== Server::STATUS_ACTIVE || $website->provisioning_status !== Website::STATUS_ACTIVE) {
                continue;
            }
            $result = $shell->run($website->server, self::script($website, $this->hibernate, $replicas, $environment->maintenance_at !== null));
            if (! $result->successful()) {
                throw new RuntimeException('Couldn’t '.($this->hibernate ? 'hibernate' : 'start').' '.$website->name.': '.$result->combined());
            }
        }
        $environment->forceFill($this->hibernate ? ['hibernated_at' => now()] : ['hibernated_at' => null, 'last_activity_at' => now()])->save();
    }

    /**
     * Build the commands for one website: maintenance mode on or off for a Laravel release, then each installed worker
     * unit enabled and started when running and within the replicas, otherwise disabled and stopped.
     *
     * @param  Website  $website
     * @param  bool  $hibernate
     * @param  int  $replicas
     * @param  bool  $inMaintenance  whether the team put the environment into maintenance mode, which hibernation leaves alone
     * @return string
     */
    public static function script(Website $website, bool $hibernate, int $replicas, bool $inMaintenance = false): string
    {
        $slug = $website->deployment_slug;
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', $slug) !== 1) {
            throw new RuntimeException('The website directory name is invalid.');
        }
        $root = escapeshellarg("/var/www/{$slug}/current");
        $manifest = escapeshellarg("/var/www/{$slug}/shared/processes/units");
        $artisan = $hibernate ? 'down --retry=60' : 'up';
        $flag = $hibernate ? '1' : '0';
        $keep = $inMaintenance ? '1' : '0';

        return <<<BASH
        set -Eeuo pipefail
        ROOT={$root}
        if [ {$keep} = 0 ] && [ -f "\$ROOT/artisan" ]; then
            sudo -u www-data php "\$ROOT/artisan" {$artisan} >/dev/null 2>&1 || true
        fi
        if [ -f {$manifest} ]; then
            while IFS= read -r unit; do
                case "\$unit" in buildpusher-{$slug}-*.service) ;; *) continue ;; esac
                replica="\${unit%.service}"; replica="\${replica##*-}"
                if [ {$flag} = 1 ] || ! [[ "\$replica" =~ ^[0-9]+\$ ]] || [ "\$replica" -gt {$replicas} ]; then
                    systemctl disable --now "\$unit" >/dev/null 2>&1 || true
                else
                    systemctl enable --now "\$unit"
                fi
            done < {$manifest}
        fi
        BASH;
    }
}
