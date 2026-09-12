<?php

namespace App\Http\Requests;

use App\Models\StatusIncident;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class StatusIncidentRequest extends FormRequest
{
    /**
     * Provide the workspace used to scope the status-page relationship rule.
     */
    abstract protected function incidentOrganizationId(): ?int;

    /**
     * Indicate whether creation requires the status-page identity.
     */
    abstract protected function requiresStatusPage(): bool;

    /**
     * Validate shared incident and maintenance content before the operation runs.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status_page_id' => [
                $this->requiresStatusPage() ? 'required' : 'sometimes',
                'integer',
                Rule::exists('status_pages', 'id')->where('organization_id', $this->incidentOrganizationId()),
            ],
            'kind' => ['required', Rule::in(StatusIncident::KINDS)],
            'status' => ['required', Rule::in(StatusIncident::STATUSES)],
            'severity' => ['required', Rule::in(StatusIncident::SEVERITIES)],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'remediation' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * Preserve the existing kind/status compatibility message after base validation succeeds.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $this->validated();
            $validStatus = $data['kind'] === 'incident'
                ? in_array($data['status'], ['investigating', 'identified', 'monitoring', 'resolved'], true)
                : in_array($data['status'], ['scheduled', 'in_progress', 'completed'], true);
            if (! $validStatus) {
                $validator->errors()->add('status', __('Choose a status that matches the update type.'));
            }
        });
    }
}
