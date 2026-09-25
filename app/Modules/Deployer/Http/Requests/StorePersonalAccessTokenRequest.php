<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class StorePersonalAccessTokenRequest extends FormRequest
{
    /**
     * Preserve owner authorization and API entitlement denial before token-input validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->can('create', PersonalAccessToken::class)) {
            return false;
        }

        app(Entitlements::class)->enforce($user, 'api');

        return true;
    }

    /**
     * Preserve token name, allowed abilities, and expiry validation.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(['read', 'deploy', 'manage'])],
            'project_ids' => ['sometimes', 'array', 'min:1'],
            'project_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('deployer.projects', 'id')->whereIn('id', $this->user()->workspaceProjects()->pluck('projects.id')),
            ],
            'expires_in_days' => ['required', 'integer', Rule::in([30, 90, 180, 365])],
        ];
    }

    /**
     * Preserve the one-year default for omitted expiry while leaving explicit null invalid.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['expires_in_days' => 365]);
    }
}
