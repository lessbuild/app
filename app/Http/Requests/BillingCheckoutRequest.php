<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingCheckoutRequest extends FormRequest
{
    /**
     * Preserve the existing paid-plan 404 and billing-permission 403 ordering before interval validation.
     */
    public function authorize(): bool
    {
        $plan = (string) $this->route('plan');
        abort_unless(array_key_exists($plan, config('billing.plans')) && $plan !== 'free', 404);

        $user = $this->user();
        $organization = $user?->currentOrganization;
        abort_unless($organization instanceof Organization && ($user?->can('manageBilling', $organization) ?? false), 403);

        return true;
    }

    /** Validate the optional monthly/yearly billing interval. */
    public function rules(): array
    {
        return [
            'interval' => ['sometimes', Rule::in(['monthly', 'yearly'])],
        ];
    }

    /** Return the validated billing interval with the existing monthly default. */
    public function billingInterval(): string
    {
        $interval = $this->validated('interval');

        return is_string($interval) ? $interval : 'monthly';
    }
}
