<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class Entitlements
{
    public function __construct(private readonly MonetizationTelemetry $telemetry) {}

    public function allows(User|Organization $subject, string $feature): bool
    {
        if (! config('billing.enforce_entitlements', true)) {
            return true;
        }

        $owner = $subject instanceof Organization ? $subject->owner : ($subject->currentOrganization?->owner ?? $subject);
        $features = config('billing.plans.'.($owner?->billingPlan() ?? 'free').'.entitlements', []);

        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

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
