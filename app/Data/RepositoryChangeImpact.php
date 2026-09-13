<?php

namespace App\Data;

class RepositoryChangeImpact
{
    public const AFFECTED = 'affected';

    public const UNAFFECTED = 'unaffected';

    public const UNKNOWN = 'unknown';

    /**
     * Carry a conservative automatic-deployment path decision.
     *
     * @param  'affected'|'unaffected'|'unknown'  $status  Whether configured paths require an automatic deployment.
     * @param  list<string>|null  $changedPaths  Bounded normalized paths supplied by the source provider, or null when unavailable.
     * @param  list<string>  $matchedPaths  Changed paths that matched the configured deployment scope.
     * @param  string  $reason  Stable internal reason for the decision.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?array $changedPaths = null,
        public readonly array $matchedPaths = [],
        public readonly string $reason = '',
    ) {}

    /**
     * Determine whether the delivery can be safely skipped by the automatic path filter.
     */
    public function isUnaffected(): bool
    {
        return $this->status === self::UNAFFECTED;
    }
}
