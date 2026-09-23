<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Workspace;
use Illuminate\Validation\ValidationException;

final class WorkspacePlanLimits
{
    /**
     * @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool}
     */
    public function applicationCapacity(Workspace $workspace): array
    {
        $limit = $this->applicationLimit($workspace);
        $used = $workspace->applications()->count();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'at_limit' => $limit !== null && $used >= $limit,
        ];
    }

    public function assertApplicationCapacity(Workspace $workspace): void
    {
        $capacity = $this->applicationCapacity($workspace);

        if (! $capacity['at_limit']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'The '.$this->planName($workspace).' plan allows '.$capacity['limit'].' application'.($capacity['limit'] === 1 ? '' : 's').'. Upgrade the plan or archive an existing application before adding another.',
        ]);
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool}
     */
    public function seatCapacity(Workspace $workspace): array
    {
        $limit = $this->seatLimit($workspace);
        $used = $workspace->members()->count();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'at_limit' => $limit !== null && $used >= $limit,
        ];
    }

    public function assertSeatCapacity(Workspace $workspace): void
    {
        $capacity = $this->seatCapacity($workspace);

        if (! $capacity['at_limit']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'The '.$this->planName($workspace).' plan includes '.$capacity['limit'].' seat'.($capacity['limit'] === 1 ? '' : 's').'. Upgrade the plan before inviting another teammate.',
        ]);
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, at_limit: bool}
     */
    public function dashboardCapacity(Workspace $workspace): array
    {
        $limit = $this->dashboardLimit($workspace);
        $used = $workspace->dashboards()->count();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'at_limit' => $limit !== null && $used >= $limit,
        ];
    }

    public function assertDashboardCapacity(Workspace $workspace): void
    {
        $capacity = $this->dashboardCapacity($workspace);

        if (! $capacity['at_limit']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'The '.$this->planName($workspace).' plan allows '.$capacity['limit'].' saved dashboard'.($capacity['limit'] === 1 ? '' : 's').'. Upgrade the plan before creating another dashboard.',
        ]);
    }

    public function assertEscalationCapacity(Workspace $workspace, int $steps): void
    {
        $limit = $this->escalationStepLimit($workspace);

        if ($limit === null || $steps <= $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'escalations' => 'The '.$this->planName($workspace).' plan allows '.$limit.' escalation step'.($limit === 1 ? '' : 's').'. Upgrade the plan to add more response layers.',
        ]);
    }

    public function deploymentContextMinutes(Workspace $workspace): int
    {
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.deployment_context_minutes', 0);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function telemetryGuardrailsEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.telemetry_guardrails', false);
    }

    public function sloBurnRateEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.slo_burn_rate', false);
    }

    public function sloBurnRateAlertsEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.slo_burn_rate_alerts', false);
    }

    public function sloReportsEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.slo_reports', false);
    }

    public function anomalyDetectionEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.anomaly_detection', false);
    }

    public function logPatternAlertsEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.log_pattern_alerts', false);
    }

    public function auditLogEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.audit_log', false);
    }

    public function issueDigestEnabled(Workspace $workspace): bool
    {
        return (bool) config('monitor.beacon.plans.'.$workspace->plan.'.issue_digest', false);
    }

    private function applicationLimit(Workspace $workspace): ?int
    {
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.apps');

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function seatLimit(Workspace $workspace): ?int
    {
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.seats');

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function dashboardLimit(Workspace $workspace): ?int
    {
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.dashboards');

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public function escalationStepLimit(Workspace $workspace): ?int
    {
        $value = config('monitor.beacon.plans.'.$workspace->plan.'.escalation_steps');

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function planName(Workspace $workspace): string
    {
        return (string) config('monitor.beacon.plans.'.$workspace->plan.'.name', ucfirst($workspace->plan));
    }
}
