<?php

namespace App\Http\Requests;

use App\Models\OperationalIncident;
use Illuminate\Foundation\Http\FormRequest;

class StoreOperationalIncidentNoteRequest extends FormRequest
{
    /**
     * Authorize the incident before validating or flashing a timeline message.
     */
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof OperationalIncident
            && ($this->user()?->can('note', $incident) ?? false);
    }

    /**
     * Validate the attributed timeline message.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * Return the validated timeline message.
     */
    public function message(): string
    {
        return $this->validated()['message'];
    }
}
