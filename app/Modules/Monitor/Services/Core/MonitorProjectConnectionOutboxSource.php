<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectConnectionOutboxSource;
use App\Core\Data\Connections\ProjectConnectionOutboxEvent as OutboxEventData;
use App\Modules\Monitor\Models\ProjectConnectionIncidentOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MonitorProjectConnectionOutboxSource implements ProjectConnectionOutboxSource
{
    public function product(): string
    {
        return 'monitor';
    }

    public function eventTypes(): array
    {
        return [
            ProjectConnectionIncidentOutboxEvent::OPENED,
            ProjectConnectionIncidentOutboxEvent::ACKNOWLEDGED,
            ProjectConnectionIncidentOutboxEvent::RESOLVED,
        ];
    }

    public function hasRequiredTables(): bool
    {
        return Schema::connection('monitor')->hasTable('project_connection_incident_outbox_events');
    }

    public function pendingEventIds(int $limit, ?string $eventId = null): array
    {
        $query = ProjectConnectionIncidentOutboxEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

        if ($eventId !== null) {
            $query->whereKey($eventId);
        }

        return $query->orderBy('created_at')->limit($limit)->pluck('id')->map(strval(...))->all();
    }

    public function claimDueEvent(string $eventId): ?OutboxEventData
    {
        return DB::connection('monitor')->transaction(function () use ($eventId): ?OutboxEventData {
            $event = ProjectConnectionIncidentOutboxEvent::query()
                ->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->whereKey($eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return null;
            }

            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
                'last_error_code' => null,
            ])->save();

            return $this->toData($event->refresh());
        });
    }

    public function finishClaimedEvent(OutboxEventData $event, array $attributes): bool
    {
        return ProjectConnectionIncidentOutboxEvent::query()
            ->whereKey($event->id)
            ->where('status', 'processing')
            ->where('attempts', $event->attempts)
            ->update([...$attributes, 'updated_at' => now()]) === 1;
    }

    public function retryFailedEvent(string $eventId): bool
    {
        return ProjectConnectionIncidentOutboxEvent::query()
            ->whereKey($eventId)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'last_error_code' => null,
                'last_error_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function recoverExpiredClaims(?string $eventId = null): int
    {
        $query = ProjectConnectionIncidentOutboxEvent::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(10));

        if ($eventId !== null) {
            $query->whereKey($eventId);
        }

        return $query->update([
            'status' => 'pending',
            'available_at' => now(),
            'last_error_code' => 'dispatch_lease_expired',
            'last_error_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function reconciliationEvents(?string $eventId, int $limit): array
    {
        $query = ProjectConnectionIncidentOutboxEvent::query()->whereIn('status', ['dispatched', 'failed']);

        if ($eventId !== null) {
            $query->whereKey($eventId);
        }

        return $query->orderBy('created_at')->limit($limit)->get()
            ->map(fn (ProjectConnectionIncidentOutboxEvent $event): OutboxEventData => $this->toData($event))
            ->all();
    }

    private function toData(ProjectConnectionIncidentOutboxEvent $event): OutboxEventData
    {
        return new OutboxEventData(
            sourceProduct: $this->product(),
            id: (string) $event->getKey(),
            eventType: (string) $event->event_type,
            eventVersion: (int) $event->event_version,
            sourceBuildId: null,
            sourceProjectId: null,
            sourceEnvironmentId: (string) $event->source_environment_id,
            sourceIncidentId: (string) $event->source_incident_id,
            payload: (array) $event->payload,
            status: (string) $event->status,
            attempts: (int) $event->attempts,
            createdAt: CarbonImmutable::instance($event->created_at),
        );
    }
}
