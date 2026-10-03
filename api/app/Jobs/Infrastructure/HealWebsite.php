<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Tries to bring a website back when its health check fails: restarts Caddy, PHP-FPM or the website's workers when
 * they've stopped, or gracefully reloads PHP-FPM and Caddy when everything looks up. At most three times an hour per
 * website, and what it did goes on the incident's timeline.
 */
final class HealWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The most heals per website per hour.
     *
     * @var int
     */
    public const PER_HOUR = 3;

    /**
     * Create a new HealWebsite instance.
     *
     * @param  int  $websiteId  The website that failed its health check.
     * @param  int  $incidentId  The incident its failure opened.
     */
    public function __construct(public readonly int $websiteId, public readonly int $incidentId) {}

    /**
     * Check the website's services and restart or reload them, then record what happened.
     *
     * @param  ServerShell  $shell
     * @return void
     */
    public function handle(ServerShell $shell): void
    {
        $website = Website::query()->with('server')->find($this->websiteId);
        $incident = Incident::query()->find($this->incidentId);
        if ($website === null || $incident === null || ! $website->self_healing || $website->server?->provisioning_status !== Server::STATUS_ACTIVE
            || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', (string) $website->deployment_slug) !== 1) {
            return;
        }
        $recent = IncidentActivity::query()->where('action', 'self_healed')->where('created_at', '>=', now()->subHour())
            ->whereIn('incident_id', Incident::query()->where('monitor_id', $incident->monitor_id)->select('id'))->count();
        if ($recent >= self::PER_HOUR) {
            (new IncidentActivity)->forceFill(['incident_id' => $incident->id, 'action' => 'self_heal_skipped', 'note' => __('Already tried :count times in the last hour; leaving it to you.', ['count' => self::PER_HOUR])])->save();

            return;
        }
        $result = $shell->run($website->server, self::script($website));
        $actions = array_values(array_filter(array_map('trim', preg_split('/\R/', $result->output) ?: []), fn (string $line): bool => str_starts_with($line, 'restarted ') || str_starts_with($line, 'reloaded ')));
        $note = $result->successful()
            ? ($actions === [] ? __('Everything was running; nothing to restart.') : __('Did: :actions.', ['actions' => implode(', ', $actions)]))
            : __('Couldn’t heal it: :error', ['error' => Str::limit(trim($result->errorOutput ?: $result->output), 300)]);
        (new IncidentActivity)->forceFill(['incident_id' => $incident->id, 'action' => 'self_healed', 'note' => $note, 'metadata' => ['actions' => $actions]])->save();
    }

    /**
     * Build the heal script: restart each of Caddy, the website's PHP-FPM and its enabled worker units that isn't
     * active; when all are, reload PHP-FPM and Caddy (which doesn't drop connections).
     *
     * @param  Website  $website
     * @return string
     */
    public static function script(Website $website): string
    {
        $slug = $website->deployment_slug;
        $fpm = escapeshellarg('php'.$website->phpVersion().'-fpm');
        $manifest = escapeshellarg("/var/www/{$slug}/shared/processes/units");

        return <<<BASH
        set -uo pipefail
        CHANGED=0
        for unit in caddy {$fpm}; do
            if systemctl list-unit-files "\$unit.service" >/dev/null 2>&1 && ! systemctl is-active --quiet "\$unit"; then
                systemctl restart "\$unit" && echo "restarted \$unit" && CHANGED=1
            fi
        done
        if [ -f {$manifest} ]; then
            while IFS= read -r unit; do
                case "\$unit" in buildpusher-{$slug}-*.service) ;; *) continue ;; esac
                if systemctl is-enabled --quiet "\$unit" && ! systemctl is-active --quiet "\$unit"; then
                    systemctl restart "\$unit" && echo "restarted \$unit" && CHANGED=1
                fi
            done < {$manifest}
        fi
        if [ "\$CHANGED" = 0 ]; then
            systemctl reload {$fpm} && echo "reloaded {$fpm}"
            systemctl reload caddy && echo "reloaded caddy"
        fi
        BASH;
    }
}
