<?php

namespace App\Core\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAnalyticsSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'domain' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone']];
    }
}
