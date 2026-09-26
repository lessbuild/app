<?php

namespace App\Core\Http\Requests;

use App\Core\Models\WorkspaceFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewWorkspaceFeedbackRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(WorkspaceFeedback::STATUSES)],
            'review_response' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
