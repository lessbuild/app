<?php

namespace App\Core\Contracts;

use App\Core\Data\Connections\ProjectConnectionOutboxEvent;

/** Product-owned persistence boundary for durable project-connection events. */
interface ProjectConnectionOutboxSource
{
    public function product(): string;

    /** @return list<string> */
    public function eventTypes(): array;

    public function hasRequiredTables(): bool;

    /** @return list<string> */
    public function pendingEventIds(int $limit, ?string $eventId = null): array;

    public function claimDueEvent(string $eventId): ?ProjectConnectionOutboxEvent;

    /** @param array<string, mixed> $attributes */
    public function finishClaimedEvent(ProjectConnectionOutboxEvent $event, array $attributes): bool;

    public function retryFailedEvent(string $eventId): bool;

    public function recoverExpiredClaims(?string $eventId = null): int;

    /** @return list<ProjectConnectionOutboxEvent> */
    public function reconciliationEvents(?string $eventId, int $limit): array;
}
