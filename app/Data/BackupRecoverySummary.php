<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class BackupRecoverySummary
{
    /**
     * Carry separate managed-backup and restore evidence without implying that
     * an in-place restore is an independently verified recovery drill.
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
