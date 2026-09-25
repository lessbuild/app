<?php

namespace App\Core\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveWorkspaceMonitorDestinationRequest extends WorkspaceMonitorAdministrationRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $editing = filled($this->input('destination_reference'));
        $pagerDutyCreate = ! $editing && $this->input('type') === 'pagerduty';

        return [
            'destination_reference' => ['nullable', 'string', 'max:4096'],
            'version' => [$editing ? 'required' : 'nullable', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'type' => ['required', Rule::in(['email', 'webhook', 'slack', 'teams', 'pagerduty', 'discord'])],
            'enabled' => ['required', 'boolean'],
            'recipient_reference' => [$this->input('type') === 'email' ? 'required' : 'nullable', 'string', 'max:4096'],
            'endpoint_url' => ['nullable', 'string', 'max:2048'],
            'signing_secret' => [$pagerDutyCreate ? 'required' : 'nullable', 'string', 'min:16', 'max:256'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = redirect()->back()->withErrors($validator)->withInput($this->except(['endpoint_url', 'signing_secret']));

        throw new ValidationException($validator, $response);
    }
}
