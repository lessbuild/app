<?php

namespace App\Http\Requests;

use App\Models\Provider;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IssueTemporaryWebsiteDomainRequest extends WebsiteDomainRequest
{
    /**
     * Preserve the old early missing-base-domain response after website authorization and before provider validation.
     *
     * @throws HttpResponseException The redirect response is raised by the request boundary when configuration is unavailable.
     */
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        if (blank($this->temporaryBaseDomain())) {
            throw new HttpResponseException(
                redirect()->back()->withErrors(['domain' => __('Set TEMPORARY_APP_DOMAIN before issuing temporary domains.')])
            );
        }

        return true;
    }

    /**
     * Validate the workspace Cloudflare provider used for a temporary hostname.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $website = $this->website();

        return [
            'dns_provider_id' => ['required', Rule::exists('providers', 'id')->where(fn ($query) => $query
                ->where('organization_id', $website->organization_id)
                ->where('provider', Provider::TYPE_CLOUDFLARE))],
        ];
    }

    /**
     * Return the normalized configured base domain for the temporary hostname.
     */
    public function temporaryBaseDomain(): string
    {
        return strtolower(trim((string) config('domains.temporary_base_domain')));
    }
}
