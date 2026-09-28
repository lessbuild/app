<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ServerShell;

/**
 * Puts idle environments to sleep and wakes them on the next request, reading each website's Caddy access log: a log
 * written to recently means requests, and Caddy keeps logging requests while the app is in maintenance mode.
 */
final class Hibernation
{
    /**
     * Create a new Hibernation instance.
     *
     * Watches environments for idleness and requests.
     *
     * @param  ServerShell  $shell  Reads the websites' access logs.
     * @param  Entitlements  $entitlements  Checks the plan includes hibernation.
     */
    public function __construct(private readonly ServerShell $shell, private readonly Entitlements $entitlements) {}

    /**
     * Hibernate an environment that has had no requests or deploys for its idle time, on a plan with hibernation. A
     * website with recent requests counts as activity instead.
     *
     * @param  Environment  $environment
     * @return string hibernating, active or skipped
     */
    public function evaluate(Environment $environment): string
    {
        $minutes = $environment->hibernate_after_minutes;
        if ($minutes === null || $environment->hibernated_at !== null || $environment->last_activity_at?->gt(now()->subMinutes($minutes))
            || ! $this->entitlements->for($environment->project->account)->has('deploy.hibernation')) {
            return 'skipped';
        }
        $websites = $this->reachable($environment);
        if ($websites === [] || Build::query()->whereIn('website_id', array_map(fn (array $pair): int => $pair[0]->id, $websites))->whereIn('status', Build::ACTIVE)->exists()) {
            return 'skipped';
        }
        foreach ($websites as [$website, $server]) {
            $log = escapeshellarg("/var/log/caddy/{$website->deployment_slug}.access.log");
            if (trim($this->shell->run($server, "find {$log} -mmin -{$minutes} -print 2>/dev/null || true")->output) !== '') {
                $environment->forceFill(['last_activity_at' => now()])->save();

                return 'active';
            }
        }
        ApplyEnvironmentRuntime::dispatch($environment->id, true);

        return 'hibernating';
    }

    /**
     * Wake a hibernated environment when one of its websites has logged a request since it went to sleep.
     *
     * @param  Environment  $environment
     * @return bool whether it's waking
     */
    public function wakeIfRequested(Environment $environment): bool
    {
        if ($environment->hibernated_at === null) {
            return false;
        }
        foreach ($this->reachable($environment) as [$website, $server]) {
            $log = escapeshellarg("/var/log/caddy/{$website->deployment_slug}.access.log");
            if ((int) trim($this->shell->run($server, "stat -c %Y {$log} 2>/dev/null || echo 0")->output) > $environment->hibernated_at->getTimestamp()) {
                ApplyEnvironmentRuntime::dispatch($environment->id, false);

                return true;
            }
        }

        return false;
    }

    /**
     * Get the environment's live websites on active servers, each with its server.
     *
     * @param  Environment  $environment
     * @return list<array{Website, Server}>
     */
    private function reachable(Environment $environment): array
    {
        $pairs = [];
        foreach ($environment->deployedWebsites() as $website) {
            if ($website->provisioning_status === Website::STATUS_ACTIVE && $website->server?->provisioning_status === Server::STATUS_ACTIVE
                && preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', $website->deployment_slug) === 1) {
                $pairs[] = [$website, $website->server];
            }
        }

        return $pairs;
    }
}
