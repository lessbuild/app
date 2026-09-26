<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class SaveWorkspaceMonitorStatusPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'published' => ['sometimes', 'boolean'],
            'monitor_ids' => ['sometimes', 'array', 'max:25'],
            'monitor_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $slug = Str::slug($this->input('slug'));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }
}
