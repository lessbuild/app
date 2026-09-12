<?php

namespace App\Http\Requests;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnvironmentVariableRequest extends FormRequest
{
    /**
     * Allow variable changes only for members who can update the parent environment.
     */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('update', $environment) ?? false);
    }

    /**
     * Validate an environment variable without accepting unrestricted request input.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/\A[A-Z_][A-Z0-9_]*\z/'],
            'value' => ['required', 'string', 'max:10000'],
            'is_secret' => ['nullable', 'boolean'],
            'scope' => ['required', Rule::in(EnvironmentVariable::SCOPES)],
            'rotation_due_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * Preserve the existing runtime-only default when the form omits scope.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['scope' => 'runtime']);
    }

    /**
     * Preserve the existing default that newly saved variables are secret.
     */
    public function isSecret(): bool
    {
        return $this->boolean('is_secret', true);
    }
}
