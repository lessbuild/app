<?php

namespace App\Data;

class ApplicationEnvironmentComparison
{
    /**
     * Carry safe differences between two recorded environment summaries.
     *
     * @param  list<array{field: string, from: string, to: string}>  $differences  No commands, variable keys/values or encrypted resource configuration.
     */
    public function __construct(
        public readonly ApplicationEnvironmentOverview $from,
        public readonly ApplicationEnvironmentOverview $to,
        public readonly array $differences,
    ) {}

    /**
     * Determine whether all displayed recorded fields match.
     *
     * @return bool Whether the comparison has no safe metadata differences.
     */
    public function isIdentical(): bool
    {
        return $this->differences === [];
    }
}
