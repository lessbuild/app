<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Enums\IssueStatus;
use App\Models\Environment;
use App\Models\Issue;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class RecordIssueOccurrence
{
    /**
     * Called inside the processing transaction with the application's row locked.
     *
     * @param  Environment  $environment
     * @param  array<string, mixed>  $event
     * @param  TelemetryEvent  $record
     * @param  CarbonImmutable  $receivedAt
     * @return void
     */
    public function record(Environment $environment, array $event, TelemetryEvent $record, CarbonImmutable $receivedAt): void
    {
        $title = Str::limit((string) ($event['title'] ?? $event['name'] ?? 'Unhandled exception'), 255, '');
        $fingerprint = (string) ($event['fingerprint'] ?? hash('sha256', Str::lower($title.'|'.($event['route'] ?? ''))));
        $occurredAt = $record->occurred_at->toImmutable();
        $issue = Issue::query()->lockForUpdate()->firstOrCreate(
            ['project_id' => $environment->project_id, 'fingerprint' => $fingerprint],
            [
                'environment_id' => $environment->id, 'type' => 'exception', 'severity' => $event['severity'] ?? 'error',
                'status' => IssueStatus::Open, 'title' => $title,
                'location' => isset($event['route']) ? Str::substr($event['route'], 0, 255) : null,
                'occurrences' => 1, 'affected_users' => (int) ($event['affected_users'] ?? 0),
                'first_seen_at' => $occurredAt, 'last_seen_at' => $occurredAt,
                'details' => $event['details'] ?? null, 'metadata' => $event['attributes'] ?? null,
            ],
        );
        $activity = $issue->wasRecentlyCreated ? 'detected' : null;

        if (! $issue->wasRecentlyCreated) {
            $latest = $occurredAt->greaterThanOrEqualTo($issue->last_seen_at);
            $regressed = $issue->status === IssueStatus::Resolved && ($issue->resolved_at === null
                ? $occurredAt->greaterThan($issue->last_seen_at)
                : ($receivedAt->greaterThan($issue->resolved_at) && $occurredAt->greaterThan($issue->resolved_at)));
            $expired = $issue->status === IssueStatus::Snoozed && $issue->snoozed_until?->lessThanOrEqualTo(CarbonImmutable::now('UTC'));
            $issue->forceFill([
                'occurrences' => $issue->occurrences + 1,
                'environment_id' => $latest ? $environment->id : $issue->environment_id,
                'first_seen_at' => $occurredAt->min($issue->first_seen_at),
                'last_seen_at' => $latest ? $occurredAt : $issue->last_seen_at,
                'severity' => $latest ? ($event['severity'] ?? $issue->severity) : $issue->severity,
            ]);

            if ($regressed || $expired) {
                $activity = $regressed ? 'regressed' : 'snooze_expired';
                $issue->forceFill(['status' => IssueStatus::Open, 'resolved_at' => null, 'snoozed_until' => null, 'state_version' => $issue->state_version + 1]);
            }

            $issue->save();
        }

        $record->forceFill(['issue_id' => $issue->id])->save();

        if ($activity !== null) {
            $issue->activities()->create(['action' => $activity, 'metadata' => ['event_id' => $record->id, 'status' => $issue->status->value]]);
        }
    }
}
