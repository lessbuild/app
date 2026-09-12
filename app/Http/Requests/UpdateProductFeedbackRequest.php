<?php

namespace App\Http\Requests;

use App\Models\ProductFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductFeedbackRequest extends FormRequest
{
    /**
     * Authorize a workspace manager before validating review input or flashing review content.
     */
    public function authorize(): bool
    {
        $feedback = $this->route('feedback');

        return $feedback instanceof ProductFeedback
            && ($this->user()?->can('review', $feedback) ?? false);
    }

    /**
     * Validate the review status and optional encrypted response.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ProductFeedback::STATUSES)],
            'review_response' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
