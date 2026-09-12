<?php

namespace App\Http\Requests;

use App\Http\Controllers\DashboardController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardPreferencesRequest extends FormRequest
{
    /** Dashboard layout preferences belong to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the optional ordered list of supported dashboard widgets.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'widgets' => ['nullable', 'array'],
            'widgets.*' => ['required', Rule::in(DashboardController::WIDGETS), 'distinct'],
        ];
    }
}
