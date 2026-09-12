<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Environment;
use App\Services\ControlPlaneAccess;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceEnvironmentVariablesRequest extends FormRequest
{
    /**
     * Preserve API access and environment authorization before replacement validation.
     */
    public function authorize(): bool
    {
        app(ControlPlaneAccess::class)->enforce($this, 'manage');
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('update', $environment) ?? false);
    }

    /**
     * Validate only the bounded replacement text; line parsing remains in the secret-safe action.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'variables' => ['required', 'string', 'max:50000'],
        ];
    }

    /**
     * Return replacement text explicitly without passing the unrestricted request to the action.
     */
    public function contents(): string
    {
        return (string) $this->validated('variables');
    }
}
