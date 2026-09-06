<?php

namespace App\Http\Requests;

use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class ProviderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool Whether the current workspace permits deployment changes.
     */
    public function authorize(): bool
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, string|list<string|In>> Provider identity, credentials, and monitoring rules.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'required',
            'provider' => 'required|string|max:255|in:'.implode(',', [
                Provider::TYPE_GITHUB,
                Provider::TYPE_GITLAB,
                Provider::TYPE_BITBUCKET,
                Provider::TYPE_DIGITALOCEAN,
                Provider::TYPE_HETZNER,
                Provider::TYPE_VULTR,
                Provider::TYPE_CLOUDFLARE,
            ]),
            'token' => [$this->isMethod('post') ? 'required' : 'nullable', 'string'],
            'connection_monitoring_enabled' => ['required', 'boolean'],
            'connection_check_interval_minutes' => [
                'required',
                'integer',
                Rule::in(Provider::CONNECTION_CHECK_INTERVALS),
            ],
            'connection_failure_threshold' => [
                'required',
                'integer',
                Rule::in(Provider::CONNECTION_FAILURE_THRESHOLDS),
            ],
        ];
    }

    /** Preserve saved monitoring settings when optional form controls are omitted. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'connection_monitoring_enabled' => $this->has('connection_monitoring_enabled')
                ? $this->boolean('connection_monitoring_enabled')
                : ($this->route('provider')?->connection_monitoring_enabled ?? true),
            'connection_check_interval_minutes' => $this->input(
                'connection_check_interval_minutes',
                $this->route('provider')?->connection_check_interval_minutes
                    ?? Provider::defaultConnectionCheckInterval(),
            ),
            'connection_failure_threshold' => $this->input(
                'connection_failure_threshold',
                $this->route('provider')?->connection_failure_threshold
                    ?? Provider::defaultConnectionFailureThreshold(),
            ),
        ]);
    }
}
