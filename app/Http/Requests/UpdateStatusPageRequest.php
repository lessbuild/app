<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusPageRequest extends FormRequest
{
    /**
     * Preserve selected-workspace status-page authorization and entitlement checks before validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $page = $this->route('statusPage');
        if (! $user || ! $page instanceof StatusPage || ! $user->can('update', $page)) {
            return false;
        }

        app(Entitlements::class)->enforce($page->organization, 'status_pages');

        return true;
    }

    /**
     * Preserve update validation, including an accepted-but-unchanged optional slug field.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['required', 'boolean'],
            'website_ids' => ['required', 'array', 'min:1'],
            'website_ids.*' => ['integer', Rule::exists('websites', 'id')->where('organization_id', $this->route('statusPage')?->organization_id)],
        ];
    }
}
