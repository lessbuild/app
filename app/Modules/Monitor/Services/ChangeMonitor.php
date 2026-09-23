<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ChangeMonitor
{
    private const CONDITIONS = ['request_url', 'bearer_token', 'method', 'status_min', 'status_max', 'body_contains',
        'max_duration_ms', 'timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks',
        'hostname', 'dns_record_type', 'dns_match', 'dns_expected', 'tls_port', 'tls_expiry_days', 'tcp_port',
        'heartbeat_schedule', 'heartbeat_interval_minutes', 'heartbeat_cron', 'heartbeat_timezone', 'heartbeat_grace_minutes',
        'queue_name', 'queue_settings'];

    public function __construct(
        private readonly TelemetryRedactor $redactor,
        private readonly ChangeIncident $incidents,
        private readonly PublicHttpTarget $targets,
        private readonly DnsRecordSet $dnsSets,
        private readonly HeartbeatSchedule $heartbeatSchedule,
        private readonly EvaluateQueueMonitor $queues,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?Monitor $monitor = null): Monitor
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $monitor): Monitor {
            $environment = $this->lockScope($workspace, $actor, (int) $data['environment_id']);
            $isNew = $monitor === null;
            if (! $isNew) {
                $monitor = Monitor::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($monitor->id);
                $this->version($monitor, (int) $data['version']);
            }
            $monitor ??= new Monitor;
            $type = $data['check_type'] ?? ($monitor->type ?? 'http');
            if (! in_array($type, ['http', 'dns', 'tls', 'tcp', 'heartbeat', 'queue'], true) || (! $isNew && $type !== $monitor->type)) {
                throw ValidationException::withMessages(['check_type' => 'The monitor type cannot be changed. Create a separate monitor.']);
            }
            $oldTarget = $isNew || $type !== 'http' ? null : $this->targets->parse($monitor->request_url);
            $values = Arr::only($data, ['timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks', 'enabled']);
            $values['name'] = $this->redactor->redact(['name' => $data['name']])['name'];
            $values['type'] = $type;
            if ($type === 'queue') {
                if ($data['enabled'] && $environment->status !== 'active') {
                    throw ValidationException::withMessages(['enabled' => 'Resume the environment before enabling its queue monitor.']);
                }
                $values = array_replace($values, [
                    'request_url' => null, 'interval_minutes' => 1, 'timeout_seconds' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1,
                    'queue_name' => $this->redactor->redact(['name' => $data['queue_name']])['name'],
                    'queue_settings' => QueueMonitorSettings::normalize($data['queue_settings']),
                ]);
            } elseif ($type === 'heartbeat') {
                if ($data['enabled'] && $environment->status !== 'active') {
                    throw ValidationException::withMessages(['enabled' => 'Resume the environment before enabling its heartbeat monitor.']);
                }
                $values = array_replace($values, Arr::only($data, ['heartbeat_schedule', 'heartbeat_grace_minutes']), [
                    'request_url' => null, 'interval_minutes' => 1, 'timeout_seconds' => 1,
                    'trigger_checks' => 1, 'recovery_checks' => 1,
                    'heartbeat_interval_minutes' => $data['heartbeat_schedule'] === 'interval' ? (int) $data['heartbeat_interval_minutes'] : null,
                    'heartbeat_cron' => $data['heartbeat_schedule'] === 'cron' ? preg_replace('/\s+/', ' ', trim($data['heartbeat_cron'])) : null,
                    'heartbeat_timezone' => $data['heartbeat_schedule'] === 'cron' ? $data['heartbeat_timezone'] : 'UTC',
                ]);
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
                            throw ValidationException::withMessages(['dns_expected' => 'Enter valid expected records for the selected type.']);
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
                $this->incidents->close($incident, 'monitor_changed', $now, $actor);
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
                    $this->cancelChecks($monitor);
                }
                if ($type === 'heartbeat') {
                    $this->heartbeatSchedule->reset($monitor, $now);
                } elseif ($type === 'queue') {
                    $this->queues->reset($monitor, $now);
                }
            }
            $monitor->forceFill(['environment_id' => $environment->id, 'state_version' => $isNew ? 0 : $monitor->state_version + 1])->save();
            $ids = array_values(array_unique(array_map('intval', $data['destinations'] ?? [])));
            sort($ids);
            $destinations = AlertDestination::forWorkspace($workspace)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if (count($ids) > 5 || $destinations->count() !== count($ids)) {
                throw ValidationException::withMessages(['destinations' => 'Choose up to five destinations from this workspace.']);
            }
            if ($ids !== [] && ! $data['opened'] && ! $data['recovered']) {
                throw ValidationException::withMessages(['opened' => 'Choose at least one incident event.']);
            }
            $monitor->destinations()->sync($destinations->mapWithKeys(fn (AlertDestination $destination): array => [
                $destination->id => ['opened' => (bool) $data['opened'], 'recovered' => (bool) $data['recovered']],
            ])->all());
            $this->audit->record($workspace, $actor, $isNew ? 'monitor.created' : 'monitor.updated', $monitor, [
                'label' => $monitor->name, 'type' => $monitor->type, 'environment' => $environment->name, 'enabled' => $monitor->enabled, 'destinations' => $ids,
            ]);

            return $monitor;
        }, attempts: 3);
    }

    public function archive(Monitor $monitor, Workspace $workspace, User $actor, int $version): void
    {
        DB::connection('monitor')->transaction(function () use ($monitor, $workspace, $actor, $version): void {
            $environment = $this->lockScope($workspace, $actor, $monitor->environment_id);
            $monitor = Monitor::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($monitor->id);
            $this->version($monitor, $version);
            $incident = $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first();
            if ($incident !== null) {
                $this->incidents->close($incident, 'monitor_archived', CarbonImmutable::now('UTC'), $actor);
            }
            $this->cancelChecks($monitor);
            $monitor->forceFill([
                'enabled' => false, 'next_check_at' => null,
                'state_version' => $monitor->state_version + 1, 'config_revision' => $monitor->config_revision + 1,
                ...($monitor->type === 'heartbeat' ? ['heartbeat_token_hash' => null, 'heartbeat_due_at' => null] : []),
                ...($monitor->type === 'queue' ? ['queue_token_hash' => null, 'queue_snapshot_id' => null] : []),
            ])->save();
            $monitor->delete();
            $this->audit->record($workspace, $actor, 'monitor.archived', $monitor, ['label' => $monitor->name, 'type' => $monitor->type, 'environment' => $environment->name]);
        }, attempts: 3);
    }

    private function lockScope(Workspace $workspace, User $actor, int $environmentId): Environment
    {
        $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
        Gate::forUser($actor)->authorize('update', $workspace);
        abort_unless($actor->hasVerifiedEmail(), 403);
        $environment = Environment::forWorkspace($workspace)->findOrFail($environmentId);
        $application = Application::query()->whereBelongsTo($workspace)->lockForUpdate()->findOrFail($environment->application_id);

        return Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($environmentId);
    }

    private function version(Monitor $monitor, int $version): void
    {
        abort_unless($monitor->state_version === $version, 409, 'This monitor changed. Refresh before trying again.');
    }

    private function cancelChecks(Monitor $monitor): void
    {
        if ($monitor->type === 'heartbeat') {
            $monitor->heartbeatRuns()->whereIn('status', ['running', 'timed_out'])->whereNull('terminal_signal')
                ->update(['status' => 'cancelled']);
        }
        $jobs = $monitor->checks()->whereIn('status', ['queued', 'running'])->whereNotNull('queue_job_uuid')->select('queue_job_uuid');
        DB::connection('monitor')->table('jobs')->where('queue', MonitorQueue::NAME)->whereIn('telemetry_uuid', $jobs)->whereNull('reserved_at')->delete();
        $monitor->checks()->whereIn('status', ['queued', 'running'])->update([
            'status' => 'cancelled', 'outcome' => 'unknown', 'reason' => 'source_changed',
            'finished_at' => now('UTC'), 'processing_token' => null, 'lease_until' => null,
        ]);
    }
}
