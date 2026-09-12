<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Environment;
use App\Services\ControlPlaneAccess;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;

class ScaleEnvironmentRequest extends FormRequest
{
    /**
     * Preserve API access, environment authorization, and scaling entitlement before replica validation.
     */
    public function authorize(): bool
    {
        app(ControlPlaneAccess::class)->enforce($this, 'manage');
        $environment = $this->route('environment');
        if (! $environment instanceof Environment
            || ! ($this->user()?->can('update', $environment) ?? false)) {
            return false;
        }

        app(Entitlements::class)->enforce($environment->project->organization, 'scaling');

        return true;
    }

    /**
     * Preserve the API replica field and environment-specific bounds.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment) {
            return [];
        }

        return [
            'replicas' => ['required', 'integer', 'min:1', 'gte:'.$environment->minimum_replicas, 'lte:'.$environment->maximum_replicas],
        ];
    }
}
