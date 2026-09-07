<?php

namespace App\Http\Requests;

use App\Models\Server;
use App\Rules\Hostname;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportWebsiteRequest extends FormRequest
{
    /**
     * Allow existing-website imports only when the user has deployment permission in the current workspace.
     */
    public function authorize(): bool
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /**
     * Require an active workspace server, valid hostname, and unique safe application-directory slug.
     *
     * @return array<string, list<mixed>> Validation rules for existing-application import fields.
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')->where(fn (Builder $query): Builder => $query->where('organization_id', $this->user()->current_organization_id)->where('provisioning_status', Server::STATUS_ACTIVE))],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:1000'],
            'url' => ['required', 'string', 'max:255', new Hostname],
            'deployment_slug' => ['required', 'string', 'max:32', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique('websites')->where('organization_id', $this->user()->current_organization_id)->whereNull('deleted_at')],
        ];
    }

    /**
     * Strip URL schemes and trailing slashes, then lowercase the imported hostname and deployment-directory slug.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'url' => strtolower(rtrim(preg_replace('#^https?://#i', '', trim((string) $this->input('url'))), '/')),
            'deployment_slug' => strtolower(trim((string) $this->input('deployment_slug'))),
        ]);
    }
}
