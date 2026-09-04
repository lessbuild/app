<?php

namespace App\Http\Requests;

use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'required',
            'provider' => 'required|string|max:255|in:'.implode(',', [
                Provider::TYPE_GITHUB,
                Provider::TYPE_GITLAB,
                Provider::TYPE_BITBUCKET,
                Provider::TYPE_DIGITALOCEAN,
            ]),
            'token' => [$this->isMethod('post') ? 'required' : 'nullable', 'string'],
            'connection_monitoring_enabled' => ['required', 'boolean'],
            'connection_check_interval_minutes' => [
                'required',
                'integer',
                Rule::in(Provider::CONNECTION_CHECK_INTERVALS),
            ],
        ];
    }

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
        ]);
    }
}
