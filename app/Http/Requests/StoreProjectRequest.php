<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Allow application creation only for an actor with deployment permission in the current organization.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'preset' => ['required', Rule::in(array_keys(config('application-templates', [])))],
        ];
    }

    /**
     * Preserve the existing default for omitted presets while retaining explicit null for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'preset' => $this->input('preset', 'laravel'),
        ]);
    }
}
