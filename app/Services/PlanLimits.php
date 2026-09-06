<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanLimits
{
    public function __construct(private readonly MonetizationTelemetry $telemetry) {}

    /** @return array{plan: string, used: int, limit: int|null, allowed: bool} */
    public function usage(User $user, string $resource): array
    {
        if ($user->currentOrganization) {
            return $this->usageForOrganization($user->currentOrganization, $resource);
        }

        $plan = $user->billingPlan();
        $limit = config("billing.plans.{$plan}.limits.{$resource}");
        $used = match ($resource) {
            'servers' => $user->servers()->count(),
            'websites' => $user->websites()->count(),
            'members' => 1,
            default => throw new \InvalidArgumentException("Unsupported billing resource [{$resource}]."),
        };

        return [
            'plan' => $plan,
            'used' => $used,
            'limit' => $limit,
            'allowed' => ! config('billing.enforce_limits') || $limit === null || $used < $limit,
        ];
    }

    /** @return array{plan: string, used: int, limit: int|null, allowed: bool} */
    public function usageForOrganization(Organization $organization, string $resource): array
    {
        $plan = $organization->owner->billingPlan();
        $limit = config("billing.plans.{$plan}.limits.{$resource}");
        $used = match ($resource) {
            'servers' => $organization->servers()->count(),
            'websites' => $organization->websites()->count(),
            'members' => $organization->members()->count()
                + $organization->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            default => throw new \InvalidArgumentException("Unsupported billing resource [{$resource}]."),
        };

        return [
            'plan' => $plan,
            'used' => $used,
            'limit' => $limit,
            'allowed' => ! config('billing.enforce_limits') || $limit === null || $used < $limit,
        ];
    }

    public function enforce(User $user, string $resource): void
    {
        $usage = $this->usage($user, $resource);

        $this->enforceUsage($usage, $resource, $user->currentOrganization);
    }

    public function enforceForOrganization(Organization $organization, string $resource): void
    {
        $this->enforceUsage($this->usageForOrganization($organization, $resource), $resource, $organization);
    }

    public function withinLimit(User $user, string $resource, Closure $callback): mixed
    {
        return DB::transaction(function () use ($user, $resource, $callback): mixed {
            $organization = Organization::query()->lockForUpdate()->findOrFail($user->current_organization_id);
            abort_unless($organization->permits($user, 'deploy'), 403);
            $this->enforceForOrganization($organization, $resource);

            return $callback($organization);
        }, 3);
    }

    /** @param array{plan: string, used: int, limit: int|null, allowed: bool} $usage */
    private function enforceUsage(array $usage, string $resource, ?Organization $organization): void
    {

        if ($usage['allowed']) {
            return;
        }

        $this->telemetry->denied('limit', $resource, $organization);

        $label = match ($resource) {
            'servers' => 'server',
            'websites' => 'website',
            'members' => 'workspace member',
        };
        throw ValidationException::withMessages([
            'plan' => __("Your :plan plan allows :limit {$label}(s). Upgrade your plan to create another.", [
                'plan' => config("billing.plans.{$usage['plan']}.name"),
                'limit' => $usage['limit'],
            ]),
        ]);
    }
}
