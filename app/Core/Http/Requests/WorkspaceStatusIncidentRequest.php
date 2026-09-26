<?php

namespace App\Core\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class WorkspaceStatusIncidentRequest extends FormRequest
{
    abstract protected function requiresStatusPage(): bool;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status_page_id' => [$this->requiresStatusPage() ? 'required' : 'sometimes', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['incident', 'maintenance'])],
            'status' => ['required', Rule::in(['investigating', 'identified', 'monitoring', 'resolved', 'scheduled', 'in_progress', 'completed'])],
            'severity' => ['required', Rule::in(['minor', 'major', 'critical'])],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'remediation' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

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
