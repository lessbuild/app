<?php

namespace App\Data;

use App\Models\OperationalIncident;

class OperationalIncidentAlert
{
    /**
     * Carry the stable identity that external destinations can use to group one active incident.
     *
     * @param  int  $incidentId  Internal incident identifier for delivery correlation.
     * @param  string  $deduplicationKey  Workspace-local category/resource key for downstream grouping.
     * @param  int  $occurrences  Number of failure observations recorded for the active incident.
     */
    public function __construct(
        public readonly int $incidentId,
        public readonly string $deduplicationKey,
        public readonly int $occurrences,
    ) {}

    /**
     * Build delivery metadata from the persisted active incident.
     *
     * @param  OperationalIncident  $incident  Incident already created or updated under its persistence lock.
     * @return self Stable, non-secret delivery identity and occurrence count.
     */
    public static function fromIncident(OperationalIncident $incident): self
    {
        return new self(
            incidentId: (int) $incident->id,
            deduplicationKey: "{$incident->category}-{$incident->resource_id}",
            occurrences: (int) $incident->occurrences,
        );
    }

    /**
     * Return the optional fields added to external alert payloads.
     *
     * @return array{incident_id: int, incident_occurrences: int, dedup_key: string} Non-secret grouping metadata.
     */
    public function deliveryMetadata(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'incident_occurrences' => $this->occurrences,
            'dedup_key' => $this->deduplicationKey,
        ];
    }
}
