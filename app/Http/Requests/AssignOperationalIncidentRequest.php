<?php

namespace App\Http\Requests;

use App\Models\OperationalIncident;
use Illuminate\Foundation\Http\FormRequest;

class AssignOperationalIncidentRequest extends FormRequest
{
    /**
     * Authorize the assignment before validating the selected responder.
     */
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof OperationalIncident
            && ($this->user()?->can('assign', $incident) ?? false);
    }

    /**
     * Validate the optional responder identifier while preserving the nullable assignment contract.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'integer'],
        ];
    }

    /**
     * Return the explicitly supplied responder ID, or null for an omitted/cleared assignment.
     */
    public function assignedTo(): ?int
    {
        $assignedTo = $this->validated()['assigned_to'] ?? null;

        return $assignedTo === null ? null : (int) $assignedTo;
    }
}
