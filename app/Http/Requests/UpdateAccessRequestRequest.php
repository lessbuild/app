<?php

namespace App\Http\Requests;

use App\Models\AccessRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessRequestRequest extends FormRequest
{
    /**
     * Authorize platform administration before validating a review or flashing applicant notes.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('platform-admin') ?? false;
    }

    /**
     * Validate the review status, notes and explicit invitation resend flag.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(AccessRequest::STATUSES)],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'resend_invitation' => ['nullable', 'boolean'],
        ];
    }
}
