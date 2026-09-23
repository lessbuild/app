<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanLimits
{
    /**
     * Bind telemetry for rejected subscription resource-limit checks.
     *
     * @param  MonetizationTelemetry  $telemetry  Records limit denials by workspace and capability.
     */
    public function __construct(
        private readonly MonetizationTelemetry $telemetry,
        private readonly DeployerPlanAuthority $planAuthority,
    ) {}

    /** @return array{plan: string, plan_name: string, used: int, limit: int|null, allowed: bool, plan_available: bool, limit_configured: bool} */
    public function usage(User $user, string $resource): array
    {
        if ($user->currentOrganization) {
            return $this->usageForOrganization($user->currentOrganization, $resource);
        }

        $used = match ($resource) {
            'servers' => $user->servers()->count(),
            'websites' => $user->websites()->count(),
            'members' => 1,
            'preview_deployments' => PreviewDeployment::query()
                ->whereHas('project.organization', fn ($query) => $query->where('owner_id', $user->id))
                ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))
                ->count(),
            default => throw new \InvalidArgumentException("Unsupported billing resource [{$resource}]."),
        };

        if ($this->planAuthority->usesCore()) {
            return $this->usageResult('unavailable', $used, null, false, false);
        }

        $plan = $user->billingPlan();
        $limit = config("billing.plans.{$plan}.limits.{$resource}");

        return $this->usageResult($plan, $used, $limit);
    }

    /** @return array{plan: string, plan_name: string, used: int, limit: int|null, allowed: bool, plan_available: bool, limit_configured: bool} */
    public function usageForOrganization(Organization $organization, string $resource): array
    {
        $used = match ($resource) {
            'servers' => $organization->servers()->count(),
            'websites' => $organization->websites()->count(),
            'members' => $organization->members()->count()
                + $organization->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            'preview_deployments' => $organization->previews()
                ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))
                ->count(),
            default => throw new \InvalidArgumentException("Unsupported billing resource [{$resource}]."),
        };

        if ($this->planAuthority->usesCore()) {
            $resolution = $this->planAuthority->resolve($organization);

            if (! $resolution->available) {
                return $this->usageResult('unavailable', $used, null, false, false);
            }

            $limitConfigured = $resolution->hasLimit($resource);

            return $this->usageResult(
                $resolution->planKey ?? 'unavailable',
                $used,
                $resolution->limit($resource),
                planAvailable: true,
                limitConfigured: $limitConfigured,
                planName: $resolution->planName,
            );
        }

        $plan = $organization->owner->billingPlan();
        $limit = config("billing.plans.{$plan}.limits.{$resource}");

        return $this->usageResult($plan, $used, $limit);
    }

    /**
     * Require available capacity for one resource in the user's current workspace.
     *
     * @param  User  $user  The account whose current workspace determines usage and billing.
     * @param  string  $resource  The configured resource-limit key.
     * @return void No value when usage permits another resource.
     *
     * @throws ValidationException If the applicable resource limit has been reached.
     */
    public function enforce(User $user, string $resource): void
    {
        $usage = $this->usage($user, $resource);

        $this->enforceUsage($usage, $resource, $user->currentOrganization);
    }

    /**
     * Require available plan capacity for an explicitly selected workspace.
     *
     * @param  Organization  $organization  The workspace whose owner plan and resource usage are checked.
     * @param  string  $resource  The configured resource-limit key.
     * @return void No value when another resource is allowed.
     *
     * @throws ValidationException If the applicable resource limit has been reached.
     */
    public function enforceForOrganization(Organization $organization, string $resource): void
    {
        $this->enforceUsage($this->usageForOrganization($organization, $resource), $resource, $organization);
    }

    /**
     * Run resource creation under a workspace lock after checking deploy permission and capacity.
     *
     * @param  User  $user  The account whose current workspace must allow deployments.
     * @param  string  $resource  The resource-limit key checked before invoking the callback.
     * @param  Closure(Organization): mixed  $callback  The operation executed inside the transaction for the locked workspace.
     * @return mixed The callback result; transaction failures roll back its database changes.
     */
    public function withinLimit(User $user, string $resource, Closure $callback): mixed
    {
        return DB::transaction(function () use ($user, $resource, $callback): mixed {
            $organization = Organization::query()->lockForUpdate()->findOrFail($user->current_organization_id);
            abort_unless($organization->permits($user, 'deploy'), 403);
            $this->enforceForOrganization($organization, $resource);

            return $callback($organization);
        }, 3);
    }

    /** @param array{plan: string, plan_name: string, used: int, limit: int|null, allowed: bool, plan_available: bool, limit_configured: bool} $usage */
    private function enforceUsage(array $usage, string $resource, ?Organization $organization): void
    {

        if ($usage['allowed']) {
            return;
        }

        $this->telemetry->denied('limit', $resource, $organization);

        if (! $usage['plan_available']) {
            throw ValidationException::withMessages([
                'plan' => __('We could not confirm this workspace’s Deployer plan, so this change was not made. Retry shortly or contact support.'),
            ]);
        }

        if (! $usage['limit_configured']) {
            throw ValidationException::withMessages([
                'plan' => __('We could not confirm the Deployer :resource limit for this workspace, so this change was not made. Retry shortly or contact support.', [
                    'resource' => str_replace('_', ' ', $resource),
                ]),
            ]);
        }

        $label = match ($resource) {
            'servers' => 'server',
            'websites' => 'website',
            'members' => 'workspace member',
            'preview_deployments' => 'concurrent preview environment',
        };
        throw ValidationException::withMessages([
            'plan' => __("Your :plan plan allows :limit {$label}(s). Upgrade your plan to create another.", [
                'plan' => $usage['plan_name'],
                'limit' => $usage['limit'],
            ]),
        ]);
    }

    /** @return array{plan: string, plan_name: string, used: int, limit: int|null, allowed: bool, plan_available: bool, limit_configured: bool} */
    private function usageResult(
        string $plan,
        int $used,
        ?int $limit,
        bool $planAvailable = true,
        bool $limitConfigured = true,
        ?string $planName = null,
    ): array {
        return [
            'plan' => $plan,
            'plan_name' => $planName ?? config("billing.plans.{$plan}.name", ucfirst($plan)),
            'used' => $used,
            'limit' => $limit,
            'allowed' => ! config('billing.enforce_limits')
                || ($planAvailable && $limitConfigured && ($limit === null || $used < $limit)),
            'plan_available' => $planAvailable,
            'limit_configured' => $limitConfigured,
        ];
    }
}
