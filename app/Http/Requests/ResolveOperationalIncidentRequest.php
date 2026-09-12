<?php

namespace App\Http\Requests;

use App\Models\OperationalIncident;
use Illuminate\Foundation\Http\FormRequest;

class ResolveOperationalIncidentRequest extends FormRequest
{
    /**
     * Authorize the incident before validating or flashing its resolution.
     */
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof OperationalIncident
            && ($this->user()?->can('resolve', $incident) ?? false);
    }

    /**
     * Validate the resolution recorded in the incident timeline.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * Return the validated resolution.
     */
    public function resolution(): string
    {
        return $this->validated()['resolution'];
    }
}
