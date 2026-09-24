<?php

namespace App\Core\Services\Connections;

use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ProjectResource;
use App\Modules\Monitor\Models\ProjectConnectionIncidentOutboxEvent;
use RuntimeException;

final class DispatchMonitorIncidentOutboxEvent
{
    public function dispatch(ProjectConnectionIncidentOutboxEvent $event): int
    {
        $created = 0;

        foreach ($this->deliveryCandidates($event) as [$connection, $deliveryPayload]) {
            $delivery = ProjectConnectionDelivery::query()->firstOrCreate(
                [
                    'source_event_id' => $event->getKey(),
                    'project_connection_id' => $connection->getKey(),
                ],
                [
                    'event_type' => $event->event_type,
                    'event_version' => $event->event_version,
                    'payload' => $deliveryPayload,
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                ],
            );

            if ($delivery->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /** Count eligible connection deliveries which have not yet been recorded. */
    public function missingDeliveryCount(ProjectConnectionIncidentOutboxEvent $event): int
    {
        $candidates = $this->deliveryCandidates($event);

        if ($candidates === []) {
            return 0;
        }

        $connectionIds = collect($candidates)->map(fn (array $candidate): string => (string) $candidate[0]->getKey());
        $existingConnectionIds = ProjectConnectionDelivery::query()
            ->where('source_event_id', $event->getKey())
            ->whereIn('project_connection_id', $connectionIds)
            ->pluck('project_connection_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return $connectionIds->diff($existingConnectionIds)->count();
    }

    /** @return list<array{0: ProjectConnection, 1: array<string, string>}> */
    private function deliveryCandidates(ProjectConnectionIncidentOutboxEvent $event): array
    {
        $expectedStatus = match ($event->event_type) {
            ProjectConnectionIncidentOutboxEvent::OPENED => 'open',
            ProjectConnectionIncidentOutboxEvent::ACKNOWLEDGED => 'acknowledged',
            ProjectConnectionIncidentOutboxEvent::RESOLVED => 'resolved',
            default => null,
        };
        $payload = $event->payload;

        if ($event->event_version !== 1
            || $expectedStatus === null
            || ! is_array($payload)
            || ! is_string($payload['incident_id'] ?? null)
            || ! preg_match('/\A[1-9]\d*\z/D', $payload['incident_id'])
            || $payload['incident_id'] !== (string) $event->source_incident_id
            || ($payload['status'] ?? null) !== $expectedStatus
            || ! is_string($payload['occurred_at'] ?? null)
            || ! preg_match('/\A[1-9]\d*\z/D', (string) $event->source_environment_id)) {
            throw new RuntimeException('The Monitor incident event payload is invalid.');
        }

        $sourceEnvironment = ProjectResource::query()
            ->where('product', 'monitor')
            ->where('resource_type', 'environment')
            ->where('resource_id', (string) $event->source_environment_id)
            ->where('status', 'active')
            ->first();

        if ($sourceEnvironment === null || $sourceEnvironment->environment_id === null) {
            return [];
        }

        $connections = ProjectConnection::query()
            ->where('project_id', $sourceEnvironment->project_id)
            ->where('created_at', '<=', $event->created_at)
            ->whereIn('status', ['pending', 'active', 'failed'])
            ->whereNull('disconnected_at')
            ->with(['sourceResource', 'targetResource'])
            ->get();
        $candidates = [];

        foreach ($connections as $connection) {
            $source = $connection->sourceResource;
            $target = $connection->targetResource;

            if ($source?->getKey() !== $sourceEnvironment->getKey()
                || $connection->source_environment_id !== $sourceEnvironment->environment_id
                || ! in_array(ProjectConnectionCapability::IncidentAnnotations->value, (array) $connection->capabilities, true)
                || $target?->product !== 'analytics'
                || $target->resource_type !== 'site'
                || $target->environment_id !== null
                || $target->status !== 'active'
                || $connection->target_environment_id !== null) {
                continue;
            }

            $candidates[] = [
                $connection,
                [
                    'incident_id' => $payload['incident_id'],
                    'status' => $payload['status'],
                    'occurred_at' => $payload['occurred_at'],
                    'source_environment_id' => (string) $event->source_environment_id,
                    'target_site_id' => (string) $target->resource_id,
                ],
            ];
        }

        return $candidates;
    }
}
