<?php

namespace App\Core\Data\Connections;

use Carbon\CarbonImmutable;

/** A read-only source event normalized for Core's connection dispatcher. */
final readonly class ProjectConnectionOutboxEvent
{
    public const DEPLOYER_DEPLOYMENT_SUCCEEDED = 'deployer.deployment_succeeded';

    public const MONITOR_INCIDENT_OPENED = 'monitor.incident_opened';

    public const MONITOR_INCIDENT_ACKNOWLEDGED = 'monitor.incident_acknowledged';

    public const MONITOR_INCIDENT_RESOLVED = 'monitor.incident_resolved';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $sourceProduct,
        public string $id,
        public string $eventType,
        public int $eventVersion,
        public ?string $sourceBuildId,
        public ?string $sourceProjectId,
        public ?string $sourceEnvironmentId,
        public ?string $sourceIncidentId,
        public array $payload,
        public string $status,
        public int $attempts,
        public CarbonImmutable $createdAt,
    ) {}
}
