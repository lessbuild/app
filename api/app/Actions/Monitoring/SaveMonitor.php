<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\DnsRecordSet;
use App\Services\Monitoring\HeartbeatSchedule;
use App\Services\Monitoring\IncidentLifecycle;
use App\Services\Monitoring\MonitorChanges;
use App\Services\Monitoring\PublicHttpTarget;
use App\Services\Monitoring\QueueMonitorEvaluator;
use App\Services\Monitoring\TelemetryRedactor;
use App\Support\Monitoring\QueueMonitorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveMonitor
{
    /** Changing any of these starts the monitor over: new revision, unknown health, pending checks cancelled. */
    private const CONDITIONS = ['request_url', 'bearer_token', 'method', 'status_min', 'status_max', 'body_contains',
        'max_duration_ms', 'timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks',
        'hostname', 'dns_record_type', 'dns_match', 'dns_expected', 'tls_port', 'tls_expiry_days', 'tcp_port',
        'heartbeat_schedule', 'heartbeat_interval_minutes', 'heartbeat_cron', 'heartbeat_timezone', 'heartbeat_grace_minutes',
        'queue_name', 'queue_settings', 'flow_steps'];

    /**
     * Create a new SaveMonitor instance.
     *
     * Creates or changes a monitor.
     *
     * @param  TelemetryRedactor  $redactor  Redacts the name and queue name.
     * @param  IncidentLifecycle  $lifecycle  Closes open incidents a change makes meaningless.
     * @param  PublicHttpTarget  $targets  Checks an HTTP target is a public URL.
     * @param  DnsRecordSet  $dnsSets  Normalises a DNS monitor's hostname and expected records.
     * @param  HeartbeatSchedule  $heartbeatSchedule  Restarts a heartbeat monitor's schedule when it changes.
     * @param  QueueMonitorEvaluator  $queues  Resets a queue monitor's evaluation when its settings change.
     * @param  MonitorChanges  $changes  Locks the configuration, bumps the monitor's version and cancels checks that would test the old settings.
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(
        private readonly TelemetryRedactor $redactor,
        private readonly IncidentLifecycle $lifecycle,
        private readonly PublicHttpTarget $targets,
        private readonly DnsRecordSet $dnsSets,
        private readonly HeartbeatSchedule $heartbeatSchedule,
        private readonly QueueMonitorEvaluator $queues,
        private readonly MonitorChanges $changes,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Create or change a monitor in one of the project's environments.
     *
     * @param  Project  $project
     * @param  User  $actor
     * @param  array<string, mixed>  $data  validated by MonitorRequest
     * @param  Monitor|null  $monitor
     * @return Monitor
     */
    public function handle(Project $project, User $actor, array $data, ?Monitor $monitor = null): Monitor
    {
        return DB::transaction(function () use ($project, $actor, $data, $monitor): Monitor {
            Gate::forUser($actor)->authorize($monitor === null ? 'create' : 'update', $monitor ?? [Monitor::class, $project]);
            $environment = $this->changes->lockScope($project, $actor, (string) $data['environment_id']);
            $isNew = $monitor === null;
            if (! $isNew) {
                $monitor = Monitor::query()->where('environment_id', $environment->id)->lockForUpdate()->findOrFail($monitor->id);
                $this->changes->version($monitor, (int) $data['version']);
            }
            $monitor ??= new Monitor;
            $type = $data['check_type'] ?? ($monitor->type ?? 'http');
            if (! in_array($type, ['http', 'dns', 'tls', 'tcp', 'flow', 'heartbeat', 'queue'], true) || (! $isNew && $type !== $monitor->type)) {
                throw ValidationException::withMessages(['check_type' => __('The monitor type can’t be changed. Create a separate monitor.')]);
            }
            $oldTarget = $isNew || $type !== 'http' || $monitor->request_url === null ? null : $this->targets->parse($monitor->request_url);
            $values = Arr::only($data, ['timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks', 'enabled']);
            $values['name'] = $this->redactor->redact(['name' => $data['name']])['name'];
            $values['type'] = $type;
            if ($type === 'queue') {
                $values = array_replace($values, [
                    'request_url' => null, 'interval_minutes' => 1, 'timeout_seconds' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1,
                    'queue_name' => $this->redactor->redact(['name' => $data['queue_name']])['name'],
                    'queue_settings' => QueueMonitorSettings::normalize($data['queue_settings']),
                ]);
            } elseif ($type === 'heartbeat') {
                $values = array_replace($values, Arr::only($data, ['heartbeat_schedule', 'heartbeat_grace_minutes']), [
                    'request_url' => null, 'interval_minutes' => 1, 'timeout_seconds' => 1,
                    'trigger_checks' => 1, 'recovery_checks' => 1,
                    'heartbeat_interval_minutes' => $data['heartbeat_schedule'] === 'interval' ? (int) $data['heartbeat_interval_minutes'] : null,
                    'heartbeat_cron' => $data['heartbeat_schedule'] === 'cron' ? preg_replace('/\s+/', ' ', trim($data['heartbeat_cron'])) : null,
                    'heartbeat_timezone' => $data['heartbeat_schedule'] === 'cron' ? $data['heartbeat_timezone'] : 'UTC',
                ]);
            } elseif ($type === 'flow') {
                $values['request_url'] = null;
                if (isset($data['flow_steps']) && $data['flow_steps'] !== '') {
                    $values['flow_steps'] = trim(str_replace("\r\n", "\n", (string) $data['flow_steps']));
                }
            } elseif ($type === 'http') {
                $values += Arr::only($data, ['method', 'status_min', 'status_max', 'max_duration_ms']);
                foreach (['request_url', 'bearer_token', 'body_contains'] as $secret) {
                    if (isset($data[$secret]) && $data[$secret] !== '') {
                        $values[$secret] = $data[$secret];
                    }
                }
                foreach (['bearer_token', 'body_contains'] as $secret) {
                    if ($data['clear_'.$secret] ?? false) {
                        $values[$secret] = null;
                    }
                }
                if (isset($values['request_url']) && $oldTarget !== $this->targets->parse($values['request_url'])
                    && ! isset($data['bearer_token'])) {
                    $values['bearer_token'] = null;
                }
            } else {
                $values['request_url'] = null;
                if (isset($data['hostname']) && $data['hostname'] !== '') {
                    $values['hostname'] = $this->dnsSets->hostname($data['hostname']);
                }
                if ($type === 'dns') {
                    $values += Arr::only($data, ['dns_record_type', 'dns_match']);
                    if (isset($data['dns_expected']) && $data['dns_expected'] !== '') {
                        $values['dns_expected'] = $this->dnsSets->expected($data['dns_record_type'], $data['dns_expected']);
                        if ($values['dns_expected'] === null) {
                            throw ValidationException::withMessages(['dns_expected' => __('Enter valid expected records for the selected type.')]);
                        }
                    }
                } elseif ($type === 'tls') {
                    $values += Arr::only($data, ['tls_port', 'tls_expiry_days']);
                } else {
                    $values += Arr::only($data, ['tcp_port']);
                }
            }
            $monitor->forceFill($values);
            $conditionsChanged = ! $isNew && $monitor->isDirty(self::CONDITIONS);
            $enabledChanged = ! $isNew && $monitor->isDirty('enabled');
            $now = CarbonImmutable::now('UTC');
            $incident = $isNew ? null : $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first();
            if ($incident !== null && $conditionsChanged) {
                $this->lifecycle->close($incident, 'monitor_changed', $now, $actor);
            } elseif ($incident !== null && $enabledChanged) {
                $incident->activities()->create(['actor_id' => $actor->id, 'action' => $monitor->enabled ? 'monitor_resumed' : 'monitor_paused']);
            }
            if ($isNew || $conditionsChanged || $enabledChanged) {
                $monitor->forceFill([
                    'config_revision' => $isNew ? 0 : $monitor->config_revision + 1,
                    'health' => 'unknown', 'failure_streak' => 0, 'recovery_streak' => 0,
                    'observation' => null, 'checked_at' => null, 'last_scheduled_at' => null,
                    'next_check_at' => $monitor->enabled ? $now : null,
                ]);
                if (! $isNew) {
                    $this->changes->cancelChecks($monitor);
                }
                if ($type === 'heartbeat') {
                    $this->heartbeatSchedule->reset($monitor, $now);
                } elseif ($type === 'queue') {
                    $this->queues->reset($monitor, $now);
                }
            }
            $monitor->forceFill(['environment_id' => $environment->id, 'state_version' => $isNew ? 0 : $monitor->state_version + 1])->save();
            $ids = array_values(array_unique(array_map('intval', is_array($data['destinations'] ?? null) ? $data['destinations'] : [])));
            sort($ids);
            $destinations = AlertDestination::query()->where('account_id', $project->account_id)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if (count($ids) > 5 || $destinations->count() !== count($ids)) {
                throw ValidationException::withMessages(['destinations' => __('Choose up to five destinations from this account.')]);
            }
            if ($ids !== [] && ! $data['opened'] && ! $data['recovered']) {
                throw ValidationException::withMessages(['opened' => __('Choose at least one incident event.')]);
            }
            $monitor->destinations()->sync($destinations->mapWithKeys(fn (AlertDestination $destination): array => [
                $destination->id => ['opened' => (bool) $data['opened'], 'recovered' => (bool) $data['recovered']],
            ])->all());
            $this->audit->handle($isNew ? AuditAction::MonitorCreated : AuditAction::MonitorUpdated, $actor, $project->account_id, [
                'monitor' => $monitor->name, 'project' => $project->name, 'type' => $monitor->type,
                'environment' => $environment->name, 'enabled' => $monitor->enabled,
            ], $project->id);

            return $monitor;
        }, attempts: 3);
    }
}
