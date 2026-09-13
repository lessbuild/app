<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class BackupRecoverySummary
{
    /**
     * Carry separate managed-backup, in-place restore, and isolated recovery
     * evidence without treating one kind of evidence as another.
     */
    public function __construct(
        ?CarbonInterface $latestBackupCompletedAt,
        ?CarbonInterface $latestTransportVerifiedAt,
        ?CarbonInterface $latestRestoreCompletedAt,
        public readonly ?int $latestRestoreSeconds,
        ?CarbonInterface $latestIndependentRecoveryVerificationAt = null,
    ) {
        $this->latestBackupCompletedAt = $latestBackupCompletedAt?->toImmutable();
        $this->latestTransportVerifiedAt = $latestTransportVerifiedAt?->toImmutable();
        $this->latestRestoreCompletedAt = $latestRestoreCompletedAt?->toImmutable();
        $this->latestIndependentRecoveryVerificationAt = $latestIndependentRecoveryVerificationAt?->toImmutable();
    }

    public readonly ?CarbonImmutable $latestBackupCompletedAt;

    public readonly ?CarbonImmutable $latestTransportVerifiedAt;

    public readonly ?CarbonImmutable $latestRestoreCompletedAt;

    public readonly ?CarbonImmutable $latestIndependentRecoveryVerificationAt;
}
