<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectConnectionOutboxSource;
use App\Core\Data\Connections\ProjectConnectionOutboxEvent as OutboxEventData;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeployerProjectConnectionOutboxSource implements ProjectConnectionOutboxSource
{
    public function product(): string
    {
        return 'deployer';
    }

    public function eventTypes(): array
    {
        return [DeploymentSucceededOutboxEvent::EVENT_TYPE];
    }

    public function hasRequiredTables(): bool
    {
        return Schema::connection('deployer')->hasTable('deployment_succeeded_outbox_events');
    }

    public function pendingEventIds(int $limit, ?string $eventId = null): array
    {
        $query = DeploymentSucceededOutboxEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));

        if ($eventId !== null) {
            $query->whereKey($eventId);
        }

        return $query->orderBy('created_at')->limit($limit)->pluck('id')->map(strval(...))->all();
    }

    public function claimDueEvent(string $eventId): ?OutboxEventData
    {
        return DB::connection('deployer')->transaction(function () use ($eventId): ?OutboxEventData {
            $event = DeploymentSucceededOutboxEvent::query()
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
        return DeploymentSucceededOutboxEvent::query()
            ->whereKey($event->id)
            ->where('status', 'processing')
            ->where('attempts', $event->attempts)
            ->update([...$attributes, 'updated_at' => now()]) === 1;
    }

    public function retryFailedEvent(string $eventId): bool
    {
        return DeploymentSucceededOutboxEvent::query()
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
        $query = DeploymentSucceededOutboxEvent::query()
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
        $query = DeploymentSucceededOutboxEvent::query()->whereIn('status', ['dispatched', 'failed']);

        if ($eventId !== null) {
            $query->whereKey($eventId);
        }

        return $query->orderBy('created_at')->limit($limit)->get()
            ->map(fn (DeploymentSucceededOutboxEvent $event): OutboxEventData => $this->toData($event))
            ->all();
    }

    private function toData(DeploymentSucceededOutboxEvent $event): OutboxEventData
    {
        return new OutboxEventData(
            sourceProduct: $this->product(),
            id: (string) $event->getKey(),
            eventType: (string) $event->event_type,
            eventVersion: (int) $event->event_version,
            sourceBuildId: (string) $event->source_build_id,
            sourceProjectId: (string) $event->source_project_id,
            sourceEnvironmentId: (string) $event->source_environment_id,
            sourceIncidentId: null,
            payload: (array) $event->payload,
            status: (string) $event->status,
            attempts: (int) $event->attempts,
            createdAt: CarbonImmutable::instance($event->created_at),
        );
    }
}
