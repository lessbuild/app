<?php

namespace App\Data;

use App\Enums\DeploymentObservationStatus;
use App\Models\DeploymentObservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class DeploymentObservationEvidence
{
    public readonly ?CarbonImmutable $startedAt;

    public readonly ?CarbonImmutable $lastCheckedAt;

    public readonly ?CarbonImmutable $deadlineAt;

    public readonly ?CarbonImmutable $completedAt;

    /**
     * Carry only the revision-bound outcome fields safe for an environment evidence view.
     *
     * Error text, target URLs and paths, claim tokens and lease metadata are
     * deliberately not part of this read model.
     *
     * @param  CarbonInterface|null  $startedAt  Time the first observation attempt began.
     * @param  CarbonInterface|null  $lastCheckedAt  Time the last bounded probe completed.
     * @param  CarbonInterface|null  $deadlineAt  End of the configured observation window.
     * @param  CarbonInterface|null  $completedAt  Time a terminal outcome was recorded.
     */
    public function __construct(
        public int $id,
        public int $buildId,
        public string $revision,
        public string $status,
        public int $durationMinutes,
        public int $successfulChecks,
        public ?int $lastHttpStatus,
        public ?int $lastDurationMs,
        ?CarbonInterface $startedAt,
        ?CarbonInterface $lastCheckedAt,
        ?CarbonInterface $deadlineAt,
        ?CarbonInterface $completedAt,
    ) {
        $this->startedAt = $startedAt?->toImmutable();
        $this->lastCheckedAt = $lastCheckedAt?->toImmutable();
        $this->deadlineAt = $deadlineAt?->toImmutable();
        $this->completedAt = $completedAt?->toImmutable();
    }

    /**
     * Build safe evidence from the selected observation columns.
     *
     * @param  DeploymentObservation  $observation  Observation with no sensitive columns selected.
     * @return self Secret-safe deployment evidence.
     */
    public static function fromModel(DeploymentObservation $observation): self
    {
        return new self(
            id: (int) $observation->id,
            buildId: (int) $observation->build_id,
            revision: (string) $observation->revision,
            status: (string) $observation->status,
            durationMinutes: (int) $observation->duration_minutes,
            successfulChecks: (int) $observation->successful_checks,
            lastHttpStatus: $observation->last_http_status === null ? null : (int) $observation->last_http_status,
            lastDurationMs: $observation->last_duration_ms === null ? null : (int) $observation->last_duration_ms,
            startedAt: $observation->started_at,
            lastCheckedAt: $observation->last_checked_at,
            deadlineAt: $observation->deadline_at,
            completedAt: $observation->completed_at,
        );
    }

    /** @return DeploymentObservationStatus|null The known status, or null for a legacy value. */
    public function statusEnum(): ?DeploymentObservationStatus
    {
        return DeploymentObservationStatus::tryFrom($this->status);
    }
}
