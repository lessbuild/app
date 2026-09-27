<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Models\Dashboard;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation happens in SaveDashboard (account settings access). */
final class DashboardRequest extends FormRequest
{
    /**
     * A dashboard's name, description, range and at least one widget, each at most once.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'description' => ['nullable', 'string', 'max:1000'],
            'range' => ['required', 'string', Rule::in(array_keys(TelemetrySummaryQuery::RANGES))],
            'widgets' => ['required', 'array', 'min:1', 'max:'.count(Dashboard::WIDGETS)],
            'widgets.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Dashboard::WIDGETS))],
        ];
    }

    /**
     * A clearer message when no widget is chosen.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['widgets.required' => __('Choose at least one widget.')];
    }
}
