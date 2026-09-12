<?php

namespace App\Http\Requests;

use App\Models\Environment;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScaleEnvironmentRequest extends FormRequest
{
    /**
     * Preserve the environment policy and scaling entitlement checks before web validation.
     */
    public function authorize(): bool
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment
            || ! ($this->user()?->can('update', $environment) ?? false)) {
            return false;
        }

        app(Entitlements::class)->enforce($environment->project->organization, 'scaling');

        return true;
    }

    /**
     * Preserve the web scaling field names, bounds, and nullable idle-timeout behavior.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20', 'gte:minimum_replicas'],
            'desired_replicas' => ['required', 'integer', 'gte:minimum_replicas', 'lte:maximum_replicas'],
            'hibernate_after_minutes' => ['nullable', 'integer', Rule::in([5, 15, 30, 60, 120, 1440])],
        ];
    }
}
