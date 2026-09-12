<?php

namespace App\Http\Requests;

use App\Models\EnvironmentResource;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;

class CloneDatabaseRequest extends FormRequest
{
    /**
     * Preserve source resource lookup, management, and entitlement ordering before clone-input validation.
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

        return true;
    }

    /**
     * Validate the target identity and destructive confirmation input.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'target_resource_id' => ['required', 'integer', 'different:source_resource_id'],
            'confirmation' => ['required', 'string', 'max:50'],
        ];
    }
}
