<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInfrastructureBudgetRequest extends FormRequest
{
    /** Preserve manager and entitlement checks before budget validation. */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;
        if (! $organization instanceof Organization || ! ($user?->can('manage', $organization) ?? false)) {
            return false;
        }

        app(Entitlements::class)->enforce($organization, 'cost_controls');

        return true;
    }

    /**
     * Validate the optional positive monthly infrastructure budget.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'monthly_infrastructure_budget' => ['nullable', 'numeric', 'between:1,1000000'],
        ];
    }
}
