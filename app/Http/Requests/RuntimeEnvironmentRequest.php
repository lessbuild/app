<?php

namespace App\Http\Requests;

use App\Models\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RuntimeEnvironmentRequest extends FormRequest
{
    /**
     * Preserve the environment policy check before runtime-state validation.
     * Hibernation entitlement remains in the operation because it depends on the validated state.
     */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('update', $environment) ?? false);
    }

    /**
     * Preserve the existing running/hibernated state contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', Rule::in(['running', 'hibernated'])],
        ];
    }
}
