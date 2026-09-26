<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkspaceCustomerStatusPageRequest extends FormRequest
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
            'slug' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['required', 'boolean'],
            'website_ids' => ['required', 'array', 'min:1'],
            'website_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
