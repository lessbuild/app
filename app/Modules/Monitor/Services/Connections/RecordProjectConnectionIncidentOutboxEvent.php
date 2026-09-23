<?php

namespace App\Modules\Monitor\Services\Connections;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\ProjectConnectionIncidentOutboxEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RecordProjectConnectionIncidentOutboxEvent
{
    /** @return ProjectConnectionIncidentOutboxEvent|null */
    public function record(Incident $incident, string $transition): ?ProjectConnectionIncidentOutboxEvent
    {
        $eventType = match ($transition) {
            'opened' => ProjectConnectionIncidentOutboxEvent::OPENED,
            'acknowledged' => ProjectConnectionIncidentOutboxEvent::ACKNOWLEDGED,
            'resolved' => ProjectConnectionIncidentOutboxEvent::RESOLVED,
            default => throw new LogicException('Unsupported Monitor incident transition.'),
        };

        if (DB::connection('monitor')->transactionLevel() < 1) {
            throw new LogicException('Monitor incident connection events must be recorded in the incident transaction.');
        }

        $source = $incident->source();
        $sourceEnvironmentId = $source?->environment_id;
        $occurredAt = match ($transition) {
            'opened' => $incident->opened_at,
            'acknowledged' => $incident->acknowledged_at,
            'resolved' => $incident->resolved_at,
        };

        if ($sourceEnvironmentId === null || $occurredAt === null) {
            return null;
        }

        return ProjectConnectionIncidentOutboxEvent::query()->firstOrCreate(
            [
                'event_type' => $eventType,
                'source_incident_id' => (string) $incident->getKey(),
            ],
            [
                'event_version' => 1,
                'source_environment_id' => (string) $sourceEnvironmentId,
                'payload' => [
                    'incident_id' => (string) $incident->getKey(),
                    'status' => match ($transition) {
                        'opened' => 'open',
                        'acknowledged' => 'acknowledged',
                        'resolved' => 'resolved',
                    },
                    'occurred_at' => $occurredAt->toISOString(),
                ],
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }
}
