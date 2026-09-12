<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecipeReportResolutionRequest extends FormRequest
{
    /**
     * Route authorization and contributor/report ownership checks remain in the controller's locked workflow.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the optional bounded resolution note used by resolve and note-update endpoints.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Return the validated note with the existing blank-to-null and whitespace normalization semantics.
     */
    public function resolutionNote(): ?string
    {
        $note = $this->validated('resolution_note');

        return filled($note)
            ? str($note)->trim()->toString()
            : null;
    }
}
