<?php

namespace App\Modules\Monitor\Services;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\Workspace;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class ExportWorkspaceData
{
    /**
     * @param  resource  $output
     */
    public function write(Workspace $workspace, mixed $output, ?Authenticatable $principal = null): void
    {
        if ($principal !== null || app(ProductAuthentication::class)->usesCoreAuthority('monitor')) {
            abort_unless($principal !== null, 403);
            Gate::forUser($principal)->authorize('update', $workspace);
            abort_if(app(MappedProjectResourceAccess::class)->deniedResourceIds(
                $principal, 'monitor', 'application', 'workspace', $workspace->id, [], ProjectResourceAccessPurpose::HistoricalExport,
            ) === null, 403);
        }

        foreach ($this->records($workspace, $principal) as $record) {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

            if (fwrite($output, $line) === false) {
                throw new RuntimeException('The workspace export stream could not be written.');
            }
        }
    }

    /**
     * @return Generator<int, array{type: string, data: array<string, mixed>}>
     */
    private function records(Workspace $workspace, ?Authenticatable $principal): Generator
    {
        yield [
            'type' => 'export',
            'data' => [
                'format' => 'beacon-workspace-export',
                'version' => 1,
                'exported_at' => now('UTC')->toIso8601String(),
            ],
        ];

        yield [
            'type' => 'workspace',
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'plan' => $workspace->plan,
                'created_at' => $workspace->created_at?->toIso8601String(),
            ],
        ];

        foreach ($workspace->members()->select(['users.id', 'users.name', 'users.email'])->orderBy('users.id')->cursor() as $member) {
            yield [
                'type' => 'member',
                'data' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                ],
            ];
        }

        $applicationIds = Application::withTrashed()->whereBelongsTo($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->select('id');

        foreach (Application::withTrashed()->whereBelongsTo($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->orderBy('id')->cursor() as $application) {
            yield [
                'type' => 'application',
                'data' => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'slug' => $application->slug,
                    'framework' => $application->framework,
                    'framework_version' => $application->framework_version,
                    'accent' => $application->accent,
                    'deleted_at' => $application->deleted_at?->toIso8601String(),
                ],
            ];
        }

        $environmentIds = Environment::withTrashed()->whereIn('application_id', $applicationIds)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->select('id');

        foreach (Environment::withTrashed()->whereIn('application_id', $applicationIds)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->orderBy('id')->cursor() as $environment) {
            yield [
                'type' => 'environment',
                'data' => [
                    'id' => $environment->id,
                    'application_id' => $environment->application_id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'status' => $environment->status,
                    'event_count' => $environment->event_count,
                    'last_seen_at' => $environment->last_seen_at?->toIso8601String(),
                    'deleted_at' => $environment->deleted_at?->toIso8601String(),
                ],
            ];
        }

        foreach (Issue::query()->whereIn('application_id', $applicationIds)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->orderBy('id')->cursor() as $issue) {
            yield [
                'type' => 'issue',
                'data' => [
                    'id' => $issue->id,
                    'application_id' => $issue->application_id,
                    'environment_id' => $issue->environment_id,
                    'fingerprint' => $issue->fingerprint,
                    'type' => $issue->type,
                    'severity' => $issue->severity,
                    'status' => $issue->status->value,
                    'title' => $issue->title,
                    'location' => $issue->location,
                    'occurrences' => $issue->occurrences,
                    'affected_users' => $issue->affected_users,
                    'first_seen_at' => $issue->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $issue->last_seen_at?->toIso8601String(),
                    'details' => $issue->details,
                    'metadata' => $issue->metadata,
                ],
            ];
        }

        $incidents = Incident::query()->where(fn ($query) => $query
            ->whereIn('alert_rule_id', AlertRule::withTrashed()->whereIn('environment_id', $environmentIds)->select('id'))
            ->orWhereIn('monitor_id', Monitor::withTrashed()->whereIn('environment_id', $environmentIds)->select('id')));
        foreach ($incidents->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->orderBy('id')->cursor() as $incident) {
            yield [
                'type' => 'incident',
                'data' => [
                    'id' => $incident->id,
                    'alert_rule_id' => $incident->alert_rule_id,
                    'monitor_id' => $incident->monitor_id,
                    'status' => $incident->status,
                    'opened_at' => $incident->opened_at?->toIso8601String(),
                    'last_breached_at' => $incident->last_breached_at?->toIso8601String(),
                    'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
                    'resolved_at' => $incident->resolved_at?->toIso8601String(),
                    'closure_reason' => $incident->closure_reason,
                    'rule_snapshot' => $incident->rule_snapshot,
                    'opening_observation' => $incident->opening_observation,
                    'latest_observation' => $incident->latest_observation,
                ],
            ];
        }

        foreach (AuditLog::forWorkspace($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace, ProjectResourceAccessPurpose::HistoricalExport))->orderBy('id')->cursor() as $log) {
            yield [
                'type' => 'audit_log',
                'data' => [
                    'id' => $log->id,
                    'actor_id' => $log->actor_id,
                    'action' => $log->action,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'metadata' => $log->metadata,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at?->toIso8601String(),
                ],
            ];
        }

        foreach (TelemetryEvent::query()->whereIn('environment_id', $environmentIds)->orderBy('id')->cursor() as $event) {
            yield [
                'type' => 'telemetry_event',
                'data' => [
                    'id' => $event->id,
                    'environment_id' => $event->environment_id,
                    'dedupe_key' => $event->dedupe_key,
                    'trace_id' => $event->trace_id,
                    'span_id' => $event->span_id,
                    'parent_span_id' => $event->parent_span_id,
                    'type' => $event->type,
                    'severity' => $event->severity,
                    'name' => $event->name,
                    'route' => $event->route,
                    'service' => $event->service,
                    'status_code' => $event->status_code,
                    'duration_ms' => $event->duration_ms,
                    'attributes' => $event->attributes,
                    'payload' => $event->payload,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'created_at' => $event->created_at?->toIso8601String(),
                ],
            ];
        }
    }
}
