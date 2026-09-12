<?php

namespace App\Http\Requests;

use App\Models\Build;
use Illuminate\Foundation\Http\FormRequest;

class BuildNoteRequest extends FormRequest
{
    /** Keep note validation in the existing named session bag. */
    protected $errorBag = 'buildNote';

    /**
     * Require the bound build's note ability before validating the replacement note.
     */
    public function authorize(): bool
    {
        $build = $this->route('build');

        return $build instanceof Build
            && ($this->user()?->can('updateNote', $build) ?? false);
    }

    /**
     * Validate the bounded operator note.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'operator_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Return the validated note with the existing blank-to-null normalization.
     */
    public function note(): ?string
    {
        $note = trim($this->validated('operator_note') ?? '');

        return $note === '' ? null : $note;
    }
}
