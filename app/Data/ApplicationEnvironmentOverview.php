<?php

namespace App\Data;

class ApplicationEnvironmentOverview
{
    /**
     * Carry the secret-safe recorded state shown before a configuration review is authored.
     *
     * @param  list<array{kind: string, name: string, status: string, detail: string}>  $dependencies  Display metadata only; commands, values and encrypted configuration are excluded.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $status,
        public readonly string $branch,
        public readonly string $runtimeType,
        public readonly bool $isProtected,
        public readonly int $processCount,
        public readonly int $resourceCount,
        public readonly int $variableCount,
        public readonly int $secretCount,
        public readonly array $dependencies,
    ) {}
}
