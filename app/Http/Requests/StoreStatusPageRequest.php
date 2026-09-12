<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStatusPageRequest extends FormRequest
{
    /**
     * Preserve management authorization and status-page entitlement checks before validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->can('create', StatusPage::class)) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'status_pages');

        return true;
    }

    /**
     * Validate page details and constrain every component website to the current workspace.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['required', 'boolean'],
            'website_ids' => ['required', 'array', 'min:1'],
            'website_ids.*' => ['integer', Rule::exists('websites', 'id')->where('organization_id', $this->user()?->current_organization_id)],
        ];
    }
}
