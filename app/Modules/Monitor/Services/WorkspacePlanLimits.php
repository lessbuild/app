<?php

namespace App\Modules\Monitor\Services;

use App\Core\Data\Billing\ProductPlanResolution;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Validation\ValidationException;

final class WorkspacePlanLimits
{
    /** @var array<string, ProductPlanResolution> */
    private array $resolvedPlans = [];

    public function __construct(private readonly MonitorPlanAuthority $authority) {}

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool, plan_available: bool, limit_configured: bool}
     */
    public function applicationCapacity(Workspace $workspace): array
    {
        return $this->capacity($workspace, 'applications', 'apps', $workspace->applications()->count());
    }

    public function assertApplicationCapacity(Workspace $workspace): void
    {
        $capacity = $this->applicationCapacity($workspace);
        $this->assertPlanConfirmed($capacity);

        if (! $capacity['at_limit']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'The '.$this->planName($workspace).' plan allows '.$capacity['limit'].' application'.($capacity['limit'] === 1 ? '' : 's').'. Upgrade the plan or archive an existing application before adding another.',
        ]);
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool, plan_available: bool, limit_configured: bool}
     */
    public function seatCapacity(Workspace $workspace): array
    {
        return $this->capacity($workspace, 'seats', 'seats', $workspace->members()->count());
    }

    public function assertSeatCapacity(Workspace $workspace): void
    {
        $capacity = $this->seatCapacity($workspace);
        $this->assertPlanConfirmed($capacity);

        if (! $capacity['at_limit']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'The '.$this->planName($workspace).' plan includes '.$capacity['limit'].' seat'.($capacity['limit'] === 1 ? '' : 's').'. Upgrade the plan before inviting another teammate.',
        ]);
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool, plan_available: bool, limit_configured: bool}
     */
    public function dashboardCapacity(Workspace $workspace): array
    {
        return $this->capacity($workspace, 'dashboards', 'dashboards', $workspace->dashboards()->count());
    }

    public function assertDashboardCapacity(Workspace $workspace): void
    {
        $capacity = $this->dashboardCapacity($workspace);
        $this->assertPlanConfirmed($capacity);

        if (! $capacity['at_limit']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'The '.$this->planName($workspace).' plan allows '.$capacity['limit'].' saved dashboard'.($capacity['limit'] === 1 ? '' : 's').'. Upgrade the plan before creating another dashboard.',
        ]);
    }

    public function assertEscalationCapacity(Workspace $workspace, int $steps): void
    {
        $state = $this->limitState($workspace, 'escalation_steps', 'escalation_steps');
        $this->assertPlanConfirmed($state);
        $limit = $state['limit'];

        if ($limit === null || $steps <= $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'escalations' => 'The '.$this->planName($workspace).' plan allows '.$limit.' escalation step'.($limit === 1 ? '' : 's').'. Upgrade the plan to add more response layers.',
        ]);
    }

    public function deploymentContextMinutes(Workspace $workspace): int
    {
        $state = $this->limitState($workspace, 'deployment_context_minutes', 'deployment_context_minutes');

        return $state['plan_available'] && $state['limit_configured']
            ? max(0, (int) ($state['limit'] ?? 0))
            : 0;
    }

    public function retentionDays(Workspace $workspace): ?int
    {
        $state = $this->limitState($workspace, 'retention_days', 'retention_days');

        if (! $state['plan_available'] || ! $state['limit_configured']) {
            return null;
        }

        return $state['limit'] === null ? null : max(1, $state['limit']);
    }

    public function telemetryGuardrailsEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'telemetry_guardrails');
    }

    public function sloBurnRateEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'slo_burn_rate');
    }

    public function sloBurnRateAlertsEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'slo_burn_rate_alerts');
    }

    public function sloReportsEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'slo_reports');
    }

    public function anomalyDetectionEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'anomaly_detection');
    }

    public function logPatternAlertsEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'log_pattern_alerts');
    }

    public function auditLogEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'audit_log');
    }

    public function issueDigestEnabled(Workspace $workspace): bool
    {
        return $this->featureEnabled($workspace, 'issue_digest');
    }

    /** @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool, plan_available: bool, limit_configured: bool} */
    private function capacity(Workspace $workspace, string $resource, string $legacyKey, int $used): array
    {
        $state = $this->limitState($workspace, $resource, $legacyKey);
        $known = $state['plan_available'] && $state['limit_configured'];
        $limit = $state['limit'];

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => ! $known || $limit === null ? null : max(0, $limit - $used),
            'at_limit' => ! $known || ($limit !== null && $used >= $limit),
            'plan_available' => $state['plan_available'],
            'limit_configured' => $state['limit_configured'],
        ];
    }

    /** @return array{plan_available: bool, limit_configured: bool, limit: int|null} */
    private function limitState(Workspace $workspace, string $resource, string $legacyKey): array
    {
        if ($this->authority->usesCore()) {
            $plan = $this->resolvedPlan($workspace);

            return [
                'plan_available' => $plan->available,
                'limit_configured' => $plan->hasLimit($resource),
                'limit' => $plan->hasLimit($resource) ? $plan->limit($resource) : null,
            ];
        }

        $fallback = $legacyKey === 'retention_days'
            ? config('monitor.beacon.plans.free.retention_days', 7)
            : null;
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.'.$legacyKey, $fallback);

        return [
            'plan_available' => true,
            'limit_configured' => true,
            'limit' => is_numeric($value) ? max(0, (int) $value) : null,
        ];
    }

    private function featureEnabled(Workspace $workspace, string $feature): bool
    {
        if ($this->authority->usesCore()) {
            return $this->resolvedPlan($workspace)->allows($feature);
        }

        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.'.$feature, false);
    }

    private function resolvedPlan(Workspace $workspace): ProductPlanResolution
    {
        $key = (string) $workspace->getKey();

        return $this->resolvedPlans[$key] ??= $this->authority->resolve($workspace);
    }

    /** @param array{plan_available: bool, limit_configured: bool} $state */
    private function assertPlanConfirmed(array $state): void
    {
        if ($state['plan_available'] && $state['limit_configured']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'We could not confirm this workspace’s Monitor plan. Reconcile its Core subscription before changing this resource.',
        ]);
    }

    private function planName(Workspace $workspace): string
    {
        if ($this->authority->usesCore()) {
            return $this->resolvedPlan($workspace)->planName ?? 'Monitor';
        }

        return (string) config('monitor.beacon.plans.'.$workspace->plan.'.name', ucfirst($workspace->plan));
    }
}
