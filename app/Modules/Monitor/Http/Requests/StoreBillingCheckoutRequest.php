<?php

namespace App\Modules\Monitor\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        $plans = config('monitor.beacon.plans', []);
        $paidPlans = is_array($plans)
            ? array_keys(array_filter($plans, fn (mixed $plan): bool => is_array($plan) && (int) ($plan['price'] ?? 0) > 0))
            : [];

        return [
            'plan' => ['required', 'string', Rule::in($paidPlans)],
        ];
    }
}
