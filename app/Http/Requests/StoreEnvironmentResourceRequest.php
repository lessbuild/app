<?php

namespace App\Http\Requests;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnvironmentResourceRequest extends FormRequest
{
    /**
     * Preserve the existing resource entitlement denial before resource input validation.
     */
    public function authorize(): bool
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment
            || ! ($this->user()?->can('update', $environment) ?? false)) {
            return false;
        }

        app(Entitlements::class)->enforce($environment->project->organization, 'resources');

        return true;
    }

    /**
     * Validate the resource identity, managed mode and external variables.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]*\z/'],
            'type' => ['required', Rule::in(EnvironmentResource::TYPES)],
            'is_managed' => ['required', 'boolean'],
            'variables' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
