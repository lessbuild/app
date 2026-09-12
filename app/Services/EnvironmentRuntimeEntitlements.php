<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Project;

class EnvironmentRuntimeEntitlements
{
    /**
     * Bind paid runtime checks shared by environment creation and updates.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Enforce scaling and hibernation only when their effective values change or are enabled.
     *
     * @param  array<string, mixed>  $data  Validated environment attributes.
     * @param  Environment|null  $current  Existing values for updates, or null for creation.
     */
    public function enforce(Project $project, array $data, ?Environment $current = null): void
    {
        $scalingChanged = ! $current
            || (int) $data['minimum_replicas'] !== $current->minimum_replicas
            || (int) $data['maximum_replicas'] !== $current->maximum_replicas;
        if ($scalingChanged && ((int) ($data['minimum_replicas'] ?? 1) !== 1 || (int) ($data['maximum_replicas'] ?? 1) !== 1)) {
            $this->entitlements->enforce($project->organization, 'scaling');
        }
        $requestedHibernation = is_null($data['hibernate_after_minutes'] ?? null) ? null : (int) $data['hibernate_after_minutes'];
        $hibernationChanged = ! $current || $requestedHibernation !== $current->hibernate_after_minutes;
        if ($hibernationChanged && ! is_null($data['hibernate_after_minutes'] ?? null)) {
            $this->entitlements->enforce($project->organization, 'hibernation');
        }
    }
}
