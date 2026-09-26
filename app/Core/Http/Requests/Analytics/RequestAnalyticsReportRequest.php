<?php

namespace App\Core\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class RequestAnalyticsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'in:7,30,90,365'], 'path' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:255'], 'campaign' => ['nullable', 'string', 'max:150'],
            'device' => ['nullable', 'string', 'max:32'],
        ];
    }
}
