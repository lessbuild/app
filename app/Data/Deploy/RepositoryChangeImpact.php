<?php

declare(strict_types=1);

namespace App\Data\Deploy;

final readonly class RepositoryChangeImpact
{
    public const AFFECTED = 'affected';

    public const UNAFFECTED = 'unaffected';

    public const UNKNOWN = 'unknown';

    /**
     * Create a new RepositoryChangeImpact instance.
     *
     * Carry a conservative automatic-deployment path decision.
     *
     * @param  'affected'|'unaffected'|'unknown'  $status  Whether configured paths require an automatic deployment.
     * @param  list<string>|null  $changedPaths  Bounded normalized paths supplied by the source provider, or null when unavailable.
     * @param  list<string>  $matchedPaths  Changed paths that matched the configured deployment scope.
     * @param  string  $reason  Stable internal reason for the decision.
     */
    public function __construct(
        public string $status,
        public ?array $changedPaths = null,
        public array $matchedPaths = [],
        public string $reason = '',
    ) {}

    /**
     * Determine whether the delivery can be safely skipped by the automatic path filter.
     *
     * @return bool
     */
    public function isUnaffected(): bool
    {
        return $this->status === self::UNAFFECTED;
    }
}
