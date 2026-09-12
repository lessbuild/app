<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Environment;
use App\Services\ControlPlaneAccess;
use Illuminate\Foundation\Http\FormRequest;

class RuntimeEnvironmentRequest extends FormRequest
{
    /**
     * Preserve API access and environment authorization before runtime-state validation.
     * Hibernation entitlement remains in the operation because it depends on the validated state.
     */
    public function authorize(): bool
    {
        app(ControlPlaneAccess::class)->enforce($this, 'manage');
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('update', $environment) ?? false);
    }

    /**
     * Preserve the API running/hibernated state contract.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'in:running,hibernated'],
        ];
    }
}
