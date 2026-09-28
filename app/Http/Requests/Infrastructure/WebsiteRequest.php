<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Rules\Hostname;
use App\Support\Hostname as HostnameInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation and the server's account happen in CreateWebsite and UpdateWebsite. */
final class WebsiteRequest extends FormRequest
{
    /**
     * Get the validation rules for a website's settings. Its hostname must not be used by another website or domain
     * (its own primary domain excepted), and the health check path must be an absolute path.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $website = $this->route('website');
        $websiteId = $website instanceof Website ? $website->id : null;
        $primaryDomain = $websiteId !== null ? WebsiteDomain::query()->where('website_id', $websiteId)->where('type', 'primary')->value('id') : null;

        return [
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'server_id' => ['required', 'integer', 'min:1'],
            'url' => ['required', 'string', 'max:255', new Hostname, Rule::unique('websites', 'url')->ignore($websiteId), Rule::unique('website_domains', 'hostname')->ignore($primaryDomain)],
            'description' => ['nullable', 'string', 'max:2000'],
            'environment_id' => ['nullable', 'string', 'max:26'],
            'env_file' => ['nullable', 'string', 'max:65535'],
            'release_retention' => ['sometimes', 'integer', 'between:2,20'],
            'health_check_enabled' => ['sometimes', 'boolean'],
            'health_check_path' => ['sometimes', 'string', 'max:255', "regex:#\\A/(?!/)[A-Za-z0-9._~%!$&'()*+,;=:@/\\-]*\\z#D"],
            'health_monitoring_enabled' => ['sometimes', 'boolean'],
            'health_check_interval_minutes' => ['sometimes', 'integer', Rule::in(Website::HEALTH_CHECK_INTERVALS)],
            'health_failure_threshold' => ['sometimes', 'integer', Rule::in(Website::HEALTH_FAILURE_THRESHOLDS)],
        ];
    }

    /**
     * Clean the typed hostname and makes the health check path start with `/`.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $path = trim((string) $this->input('health_check_path', '/'));
        $this->merge(['url' => HostnameInput::fromInput($this->input('url')), 'health_check_path' => str_starts_with($path, '/') ? $path : '/'.$path]);
    }
}
