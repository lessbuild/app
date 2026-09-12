<?php

namespace App\Http\Requests;

use App\Models\EnvironmentResource;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseUserRequest extends FormRequest
{
    /**
     * Preserve resource lookup, management, entitlement, and unsupported-resource ordering before validation.
     */
    public function authorize(): bool
    {
        $resource = $this->route('resource');
        $user = $this->user();
        if (! $resource instanceof EnvironmentResource || $user === null) {
            return false;
        }

        $resource->loadMissing('environment.project.organization');
        abort_unless($resource->environment !== null, 404);
        if (! $user->can('manage', $resource)) {
            return false;
        }

        app(Entitlements::class)->enforce($resource->environment->project->organization, 'resources');
        abort_unless(in_array($resource->type, ['mysql', 'postgresql'], true), 422);

        return true;
    }

    /**
     * Validate a database credential identity, privilege, and optional expiry.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var EnvironmentResource $resource */
        $resource = $this->route('resource');

        return [
            'username' => [
                'required',
                'string',
                'max:40',
                'regex:/\A[a-zA-Z_][a-zA-Z0-9_]*\z/',
                Rule::unique('database_users')->where('environment_resource_id', $resource->id),
            ],
            'privilege' => ['required', Rule::in(['read', 'write', 'admin'])],
            'expires_in_days' => ['nullable', 'integer', Rule::in([1, 7, 30, 90])],
        ];
    }
}
