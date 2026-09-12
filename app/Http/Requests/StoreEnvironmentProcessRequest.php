<?php

namespace App\Http\Requests;

use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnvironmentProcessRequest extends FormRequest
{
    /**
     * Preserve the existing worker entitlement denial before process input validation.
     */
    public function authorize(): bool
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment
            || ! ($this->user()?->can('update', $environment) ?? false)) {
            return false;
        }

        app(Entitlements::class)->enforce($environment->project->organization, 'workers');

        return true;
    }

    /**
     * Validate an entitled process definition and restart policy.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]*\z/'],
            'type' => ['required', Rule::in(EnvironmentProcess::TYPES)],
            'command' => ['required', 'string', 'max:2000'],
            'replicas' => ['required', 'integer', 'between:1,20'],
            'restart_policy' => ['required', Rule::in(['always', 'on-failure', 'no'])],
            'restart_delay_seconds' => ['required', 'integer', 'between:0,300'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Preserve the existing restart defaults for omitted process fields.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'restart_policy' => 'always',
            'restart_delay_seconds' => 5,
        ]);
    }
}
