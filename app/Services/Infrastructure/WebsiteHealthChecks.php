<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Actions\Monitoring\ArchiveMonitor;
use App\Actions\Monitoring\SaveMonitor;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Website;

/**
 * A website's health check is a Monitoring HTTP monitor in the environment the website is linked to, so it gets
 * Monitoring's incidents, alert routing and history. This keeps that monitor in step with the website's settings, and
 * archives it when the check is turned off, the link removed, or the website deleted.
 */
class WebsiteHealthChecks
{
    /**
     * Keeps websites' health monitors in step.
     *
     * @param  SaveMonitor  $save  Creates or updates the monitor.
     * @param  ArchiveMonitor  $archive  Archives it when it's no longer wanted.
     */
    public function __construct(private readonly SaveMonitor $save, private readonly ArchiveMonitor $archive) {}

    /**
     * Creates or updates the website's health monitor from its settings when the check is on and its project has
     * Monitoring, or archives it otherwise. A monitor in another environment is replaced.
     */
    public function sync(Website $website, User $actor): void
    {
        $website->loadMissing(['environment.project', 'healthMonitor']);
        $project = $website->environment?->project;
        $wanted = $website->health_check_enabled && ! $website->trashed() && $project !== null
            && $project->enabledServices()->where('service', 'monitoring')->exists();
        $monitor = $website->healthMonitor;

        if (! $wanted) {
            if ($monitor !== null) {
                $this->archive->handle($monitor, $actor, $monitor->state_version);
                $website->forceFill(['health_monitor_id' => null])->saveQuietly();
            }

            return;
        }
        if ($monitor !== null && $monitor->environment_id !== $website->environment_id) {
            $this->archive->handle($monitor, $actor, $monitor->state_version);
            $monitor = null;
        }
        $saved = $this->save->handle($project, $actor, [
            'name' => mb_substr('Website: '.$website->name, 0, 120),
            'check_type' => 'http',
            'environment_id' => (string) $website->environment_id,
            'request_url' => 'https://'.$website->url.$website->health_check_path,
            'method' => 'GET', 'status_min' => 200, 'status_max' => 399, 'timeout_seconds' => 10,
            'interval_minutes' => in_array($website->health_check_interval_minutes, Website::HEALTH_CHECK_INTERVALS, true) ? $website->health_check_interval_minutes : 5,
            'trigger_checks' => max(1, min(10, $website->health_failure_threshold)), 'recovery_checks' => 1,
            'enabled' => $website->health_monitoring_enabled, 'opened' => true, 'recovered' => true,
            'version' => $monitor->state_version ?? 0,
        ], $monitor instanceof Monitor ? $monitor : null);
        if ($website->health_monitor_id !== $saved->id) {
            $website->forceFill(['health_monitor_id' => $saved->id])->saveQuietly();
        }
    }
}
