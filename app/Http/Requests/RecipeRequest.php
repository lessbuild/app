<?php

namespace App\Http\Requests;

use App\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeRequest extends FormRequest
{
    /**
     * Allow recipe submissions only when the user has deployment permission in the current workspace.
     */
    public function authorize(): bool
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /**
     * Validate recipe fields and require a supported category when publishing.
     *
     * @return array<string, list<string|object>> String rules and conditional/category rule objects for each field.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'script' => ['required', 'string', 'max:50000'],
            'is_published' => ['required', 'boolean'],
            'category' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->boolean('is_published')),
                'string',
                Rule::in(Recipe::CATEGORIES),
            ],
        ];
    }

    /**
     * Convert the submitted publication checkbox into a boolean before applying recipe rules.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_published' => $this->boolean('is_published'),
        ]);
    }
}
