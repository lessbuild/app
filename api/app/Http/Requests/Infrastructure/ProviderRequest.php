<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation happens in SaveProvider (account settings access). */
final class ProviderRequest extends FormRequest
{
    /**
     * Get the validation rules: a provider's name, description, type, credential (required when connecting, optional
     * when editing), a self-hosted GitLab's address, and connection-check settings.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::enum(ProviderType::class)],
            'token' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'max:4096'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'connection_monitoring_enabled' => ['sometimes', 'boolean'],
            'connection_check_interval_minutes' => ['sometimes', 'integer', Rule::in(Provider::CHECK_INTERVALS)],
            'connection_failure_threshold' => ['sometimes', 'integer', Rule::in(Provider::FAILURE_THRESHOLDS)],
        ];
    }
}
