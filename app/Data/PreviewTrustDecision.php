<?php

namespace App\Data;

class PreviewTrustDecision
{
    public const ALLOWED = 'allowed';

    public const TARGET_BRANCH_UNVERIFIED = 'preview_target_unverified';

    public const TARGET_BRANCH_MISMATCH = 'preview_target_ignored';

    public const SOURCE_MISMATCH = 'preview_source_ignored';

    public const FORK_NOT_ALLOWED = 'preview_fork_blocked';

    public const SOURCE_UNVERIFIED = 'preview_source_unverified';

    /** Carry the safe admission decision for a verified pull-request preview. */
    public function __construct(public readonly string $status) {}

    /** @return bool Whether the pull request may provision or update a preview. */
    public function allowed(): bool
    {
        return $this->status === self::ALLOWED;
    }
}
