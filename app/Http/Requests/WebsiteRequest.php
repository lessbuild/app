<?php

namespace App\Http\Requests;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\Website;
use App\Rules\Hostname;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $website = $this->route('website');
        $primaryDomainId = $website?->domains()->where('type', 'primary')->value('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'server_id' => [
                'required',
                'integer',
                Rule::exists('servers', 'id')->where(fn (Builder $query): Builder => $query
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->where('provisioning_status', Server::STATUS_ACTIVE)
                    ->whereIn('type', ServerTypeEnum::websiteHostingValues())
                    ->whereNotNull('mysql_root_password')),
            ],
            'url' => [
                'required', 'string', 'max:255', new Hostname,
                Rule::unique('websites', 'url')->ignore($this->route('website')?->id),
                Rule::unique('website_domains', 'hostname')->ignore($primaryDomainId),
            ],
            'description' => ['required', 'string'],
            'environment' => [$this->isMethod('post') ? 'required' : 'present', 'string'],
            'health_check_enabled' => ['required', 'boolean'],
            'health_monitoring_enabled' => ['required', 'boolean'],
            'health_check_interval_minutes' => [
                'required',
                'integer',
                Rule::in(Website::HEALTH_CHECK_INTERVALS),
            ],
            'health_failure_threshold' => [
                'required',
                'integer',
                Rule::in(Website::HEALTH_FAILURE_THRESHOLDS),
            ],
            'health_check_path' => [
                'required',
                'string',
                'max:255',
                "regex:#\A/(?!/)[A-Za-z0-9._~%!$&'()*+,;=:@/\-]*\z#D",
            ],
            'release_retention' => ['required', 'integer', 'between:2,20'],
        ];
    }

    public function messages(): array
    {
        return [
            'server_id.exists' => __('Select an active application server with MySQL configured.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $url = trim((string) $this->input('url'));
        $url = preg_replace('#^https?://#i', '', $url) ?? $url;

        $path = trim((string) $this->input('health_check_path', '/'));
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $this->merge([
            'url' => rtrim($url, '/'),
            'health_check_enabled' => $this->boolean('health_check_enabled'),
            'health_monitoring_enabled' => $this->boolean('health_check_enabled')
                && ($this->has('health_monitoring_enabled')
                    ? $this->boolean('health_monitoring_enabled')
                    : ($this->route('website')?->health_monitoring_enabled ?? true)),
            'health_check_interval_minutes' => $this->input(
                'health_check_interval_minutes',
                $this->route('website')?->health_check_interval_minutes
                    ?? Website::DEFAULT_HEALTH_CHECK_INTERVAL_MINUTES,
            ),
            'health_failure_threshold' => $this->input(
                'health_failure_threshold',
                $this->route('website')?->health_failure_threshold
                    ?? Website::defaultHealthFailureThreshold(),
            ),
            'health_check_path' => $path,
            'release_retention' => $this->input(
                'release_retention',
                $this->route('website')?->release_retention ?? 5,
            ),
        ]);
    }
}
