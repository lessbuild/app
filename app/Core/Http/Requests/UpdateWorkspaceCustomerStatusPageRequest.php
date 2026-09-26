<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateWorkspaceCustomerStatusPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['required', 'boolean'],
            'website_ids' => ['required', 'array', 'min:1'],
            'website_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
