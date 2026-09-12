<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Rules\Hostname;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectPreviewsRequest extends FormRequest
{
    /**
     * Preserve project authorization and the preview entitlement check before field validation.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        if (! $project instanceof Project || ! ($this->user()?->can('update', $project) ?? false)) {
            return false;
        }

        if ($this->boolean('preview_enabled')) {
            app(Entitlements::class)->enforce($project->organization, 'previews');
        }

        return true;
    }

    /**
     * Validate the normalized preview enablement, hostname and expiry settings.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'preview_enabled' => ['required', 'boolean'],
            'preview_domain' => ['required_if:preview_enabled,true', 'nullable', 'string', 'max:200', new Hostname],
            'preview_ttl_hours' => ['required', 'integer', 'between:1,720'],
        ];
    }

    /** Preserve the previous controller's boolean and protocol-prefix/trailing-slash normalization. */
    protected function prepareForValidation(): void
    {
        $domain = preg_replace('#^https?://#i', '', trim((string) $this->input('preview_domain')));

        $this->merge([
            'preview_enabled' => $this->boolean('preview_enabled'),
            'preview_domain' => rtrim((string) $domain, '/'),
        ]);
    }
}
