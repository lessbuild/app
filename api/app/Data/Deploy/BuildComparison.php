<?php

declare(strict_types=1);

namespace App\Data\Deploy;

use App\Models\Build;

/** Two deploys of the same repository side by side, and what changed between them. */
final readonly class BuildComparison
{
    /**
     * Create a new BuildComparison instance.
     *
     * @param  Build  $build  The deploy being looked at.
     * @param  Build  $baseline  The deploy it's compared with.
     * @param  int|null  $durationDelta  Seconds slower (positive) or faster (negative) than the baseline, when both finished.
     * @param  string|null  $compareUrl  The Git provider's page showing the code changes between the two revisions.
     * @param  list<BuildChange>  $changes  Settings that differ between the two environment snapshots.
     * @param  bool  $snapshotsAvailable  Whether both deploys recorded their environment, so changes could be worked out.
     */
    public function __construct(
        public Build $build,
        public Build $baseline,
        public ?int $durationDelta,
        public ?string $compareUrl,
        public array $changes,
        public bool $snapshotsAvailable,
    ) {}
}
