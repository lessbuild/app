<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class Entitlements
{
    /**
     * Bind telemetry for denied subscription feature checks.
     *
     * @param  MonetizationTelemetry  $telemetry  Records feature denials for the affected workspace.
     */
    public function __construct(private readonly MonetizationTelemetry $telemetry) {}

    /**
     * Check the workspace owner's plan for a named feature entitlement.
     *
     * @param  User|Organization  $subject  The workspace, or a user whose current workspace determines the billing owner.
     * @param  string  $feature  The entitlement key required by the action.
     * @return bool True when enforcement is disabled, a wildcard is granted or the feature is explicitly allowed.
     */
    public function allows(User|Organization $subject, string $feature): bool
    {
        if (! config('billing.enforce_entitlements', true)) {
            return true;
        }

        $owner = $subject instanceof Organization ? $subject->owner : ($subject->currentOrganization?->owner ?? $subject);
        $features = config('billing.plans.'.($owner?->billingPlan() ?? 'free').'.entitlements', []);

        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    /**
     * Require a plan entitlement and record denied attempts.
     *
     * @param  User|Organization  $subject  The workspace or user whose billing owner supplies entitlements.
     * @param  string  $feature  The feature key required to proceed.
     * @return void No value when access is allowed.
     *
     * @throws ValidationException With an upgrade message when the plan lacks the feature.
     */
    public function enforce(User|Organization $subject, string $feature): void
    {
        if (! $this->allows($subject, $feature)) {
            $organization = $subject instanceof Organization ? $subject : $subject->currentOrganization;
            $this->telemetry->denied('entitlement', $feature, $organization);
            throw ValidationException::withMessages([
                'plan' => __('Your current plan does not include :feature. Upgrade your workspace to continue.', [
                    'feature' => str_replace('_', ' ', $feature),
                ]),
            ]);
        }
    }
}
