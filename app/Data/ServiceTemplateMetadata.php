<?php

namespace App\Data;

class ServiceTemplateMetadata
{
    /**
     * Describe the operational contract of a published service template.
     *
     * @param  array<string, mixed>  $compatibility  Runtime and platform requirements.
     * @param  list<array<string, mixed>>  $resources  Required resources and credential-generation modes.
     * @param  list<array<string, mixed>>  $persistentData  Persistent locations and retention behavior.
     * @param  list<array<string, mixed>>  $readinessChecks  Supported health/readiness checks.
     * @param  array<string, mixed>  $resourceLimits  Bounded process and resource defaults.
     * @param  array<string, string>  $backupRestore  Backup and restore guidance by scope.
     * @param  array<string, string>  $upgrade  Reviewable upgrade guidance.
     * @param  array<string, string>  $failureRecovery  Recovery guidance for partial failures.
     * @param  array<string, string>  $deletion  Deletion and retention behavior.
     */
    public function __construct(
        public readonly string $version,
        public readonly array $compatibility,
        public readonly array $resources,
        public readonly array $persistentData,
        public readonly array $readinessChecks,
        public readonly array $resourceLimits,
        public readonly array $backupRestore,
        public readonly array $upgrade,
        public readonly array $failureRecovery,
        public readonly array $deletion,
    ) {}
}
