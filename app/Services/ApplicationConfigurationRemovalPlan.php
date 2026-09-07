<?php

namespace App\Services;

use App\Models\Build;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\Environment;
use App\Models\PreviewDeployment;
use App\Models\Project;
use Closure;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationRemovalPlan
{
    /** Describe local deletion only; remote workloads and data remain untouched. */
    public function plan(Project $project, string $slug, ?Environment $environment, Closure $ownershipAction): array
    {
        $action = $ownershipAction($slug, 'environment', $slug, $environment, false);
        if (! $environment) {
            if (ConfigurationOwnership::query()->where('project_id', $project->id)->where('environment_slug', $slug)->exists()) {
                $this->invalid('Resolve stale configuration ownership before removing an environment.');
            }

            return [$this->change($slug, 'environment', $slug, 'absent')];
        }
        if ($action !== 'update') {
            $this->invalid('Only configuration-owned environments can be removed. Adopt the environment in a separate review first.');
        }
        if ($environment->type === 'production' || $environment->is_protected) {
            $this->invalid('Production and protected environments cannot be removed by configuration.');
        }
        if ($environment->builds()->whereIn('status', Build::ACTIVE_STATUSES)->exists()
            || $environment->website?->hasActiveDeployment()
            || ConfigurationOperation::query()->where('environment_id', $environment->id)
                ->whereNotIn('status', ['succeeded', 'failed', 'canceled'])->exists()) {
            $this->invalid('Finish or cancel outstanding deployments and configuration operations before removing an environment.');
        }
        foreach (['deploymentSchedules', 'scalingSchedules', 'scheduledTasks', 'loadBalancers'] as $relation) {
            if ($environment->{$relation}()->exists()) {
                $this->invalid('Remove attached schedules, tasks and load balancers through their own workflows before removing an environment.');
            }
        }
        if (PreviewDeployment::query()->where('environment_id', $environment->id)
            ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))->exists()) {
            $this->invalid('Close the attached preview through its lifecycle before removing an environment.');
        }

        $changes = [];
        $targets = ['environment' => [$slug => $environment->id]];
        foreach (['processes', 'resources', 'variables'] as $kind) {
            foreach ($environment->{$kind}->sortBy('id') as $child) {
                $name = $kind === 'variables' ? $child->key : $child->name;
                if ($ownershipAction($slug, $kind, $name, $child, false) !== 'update') {
                    $this->invalid('Environment removal would delete manual objects. Adopt each child in a separate review first.');
                }
                $targets[$kind][$name] = $child->id;
                $changes[] = $this->change($slug, $kind, $name, $kind === 'resources' ? 'detach' : 'remove');
            }
        }
        foreach (ConfigurationOwnership::query()->where('project_id', $project->id)->where('environment_slug', $slug)->get() as $ownership) {
            if ((int) ($targets[$ownership->kind][$ownership->logical_name] ?? 0) !== (int) $ownership->resource_id) {
                $this->invalid('Resolve stale configuration ownership before removing an environment.');
            }
        }
        $changes[] = $this->change($slug, 'environment', $slug, 'remove');

        return $changes;
    }

    /**
     * Describe a local removal or detachment with explicit remote-effect boundaries.
     *
     * @param  string  $slug  Logical environment containing the change.
     * @param  string  $kind  Resource category shown in the plan.
     * @param  string  $name  Logical resource name.
     * @param  string  $action  Planned local action.
     * @return array{environment: string, kind: string, name: string, action: string, fields: array{}, remote_data_deleted: false, remote_services_changed: false} A mutation-free plan entry.
     */
    private function change(string $slug, string $kind, string $name, string $action): array
    {
        return ['environment' => $slug, 'kind' => $kind, 'name' => $name, 'action' => $action,
            'fields' => [], 'remote_data_deleted' => false, 'remote_services_changed' => false];
    }

    /**
     * Reject an unsafe removal with an operator-facing plan error.
     *
     * @param  string  $message  A sanitized reason why the local removal cannot proceed.
     * @return never This method always throws.
     *
     * @throws ValidationException With the supplied plan error.
     */
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['plan' => $message]);
    }
}
