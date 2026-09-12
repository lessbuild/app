<?php

namespace App\Http\Requests;

use App\Models\MetricAlertRule;
use App\Services\Entitlements;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMetricAlertRuleRequest extends FormRequest
{
    /**
     * Preserve management authorization and alert entitlement checks before input validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->can('create', MetricAlertRule::class)) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'alerts');

        return true;
    }

    /**
     * Validate metric thresholds and constrain an optional server to the current workspace.
     *
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'server_id' => ['nullable', Rule::exists('servers', 'id')->where('organization_id', $organizationId)],
            'metric' => ['required', Rule::in(MetricAlertRule::METRICS)],
            'operator' => ['required', Rule::in(['gte', 'lte'])],
            'threshold' => ['required', 'numeric', 'between:0,999999999'],
            'consecutive_breaches' => ['required', 'integer', 'between:1,10'],
            'cooldown_minutes' => ['required', 'integer', Rule::in([5, 15, 30, 60, 180, 1440])],
        ];
    }
}
