<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Models\Project;
use App\Services\Monitoring\DnsRecordSet;
use App\Support\Monitoring\FlowSteps;
use App\Support\Monitoring\QueueMonitorSettings;

final readonly class MonitorFormQuery
{
    /**
     * Describe the form for adding or editing a monitor: the kinds of monitor, their settings' limits, the account's
     * alert destinations, and (when editing) the monitor's current settings. Secrets it holds (its URL, bearer token,
     * response text, flow steps) are never sent back; the form says one is stored.
     *
     * @param  Project  $project
     * @param  Monitor|null  $monitor
     * @return array<string, mixed>
     */
    public function handle(Project $project, ?Monitor $monitor): array
    {
        $routing = $monitor?->destinations()->first()?->getRelation('pivot');

        return [
            'types' => array_map(fn (string $label): string => __($label), Monitor::TYPES),
            'intervals' => array_map(fn (int $minutes, string $label): array => ['value' => (string) $minutes, 'label' => __($label)], array_keys(Monitor::INTERVALS), Monitor::INTERVALS),
            'queueSettings' => array_map(fn (string $field, array $limits): array => [
                'field' => $field,
                'label' => __(QueueMonitorSettings::LABELS[$field]),
                'min' => $limits[0],
                'max' => $limits[1],
                'default' => QueueMonitorSettings::DEFAULTS[$field],
                'required' => in_array($field, ['report_timeout_seconds', 'worker_timeout_seconds', 'minimum_workers'], true),
            ], array_keys(QueueMonitorSettings::LIMITS), QueueMonitorSettings::LIMITS),
            'dnsTypes' => DnsRecordSet::TYPES,
            'flowExample' => FlowSteps::EXAMPLE,
            'destinations' => AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get()
                ->map(fn (AlertDestination $destination): array => ['id' => $destination->id, 'name' => $destination->name, 'type' => $destination->type->label(), 'enabled' => (bool) $destination->enabled])->values(),
            'monitor' => $monitor === null ? null : [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'type' => $monitor->type,
                'environmentId' => $monitor->environment_id,
                'version' => $monitor->state_version,
                'target' => $monitor->targetLabel(),
                'queueName' => $monitor->queue_name,
                'queueSettings' => $monitor->queue_settings ?? [],
                'heartbeatSchedule' => $monitor->heartbeat_schedule,
                'heartbeatGraceMinutes' => $monitor->heartbeat_grace_minutes,
                'heartbeatIntervalMinutes' => $monitor->heartbeat_interval_minutes,
                'heartbeatCron' => $monitor->heartbeat_cron,
                'heartbeatTimezone' => $monitor->heartbeat_timezone,
                'flowSteps' => $monitor->type === 'flow' ? count(FlowSteps::parse((string) $monitor->flow_steps)['steps']) : 0,
                'method' => $monitor->method,
                'maxDurationMs' => $monitor->max_duration_ms,
                'statusMin' => $monitor->status_min,
                'statusMax' => $monitor->status_max,
                'hasBodyContains' => $monitor->body_contains !== null,
                'hasBearerToken' => $monitor->bearer_token !== null,
                'dnsRecordType' => $monitor->dns_record_type,
                'dnsMatch' => $monitor->dns_match,
                'dnsExpected' => count($monitor->dns_expected ?? []),
                'tlsPort' => $monitor->tls_port,
                'tlsExpiryDays' => $monitor->tls_expiry_days,
                'tcpPort' => $monitor->tcp_port,
                'intervalMinutes' => $monitor->interval_minutes,
                'timeoutSeconds' => $monitor->timeout_seconds,
                'triggerChecks' => $monitor->trigger_checks,
                'recoveryChecks' => $monitor->recovery_checks,
                'enabled' => (bool) $monitor->enabled,
                'destinations' => $monitor->destinations()->pluck('alert_destinations.id')->all(),
                'notifyOpened' => (bool) ($routing?->getAttribute('opened') ?? true),
                'notifyRecovered' => (bool) ($routing?->getAttribute('recovered') ?? true),
            ],
        ];
    }
}
