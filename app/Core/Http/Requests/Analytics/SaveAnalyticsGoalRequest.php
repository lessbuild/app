<?php

namespace App\Core\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class SaveAnalyticsGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'], 'kind' => ['required', 'string', 'in:path,event'],
            'match_type' => ['required', 'string', 'in:exact,prefix'], 'match_value' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
