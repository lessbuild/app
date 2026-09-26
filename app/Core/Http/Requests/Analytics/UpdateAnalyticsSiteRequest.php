<?php

namespace App\Core\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAnalyticsSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'], 'domains' => ['required', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone'], 'excluded_paths' => ['nullable', 'string', 'max:4000'],
            'collection_enabled' => ['sometimes', 'boolean'], 'collection_paused' => ['sometimes', 'boolean'],
        ];
    }
}
