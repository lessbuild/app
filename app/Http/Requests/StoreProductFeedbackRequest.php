<?php

namespace App\Http\Requests;

use App\Models\ProductFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductFeedbackRequest extends FormRequest
{
    /**
     * Allow feedback submission only for a member of the currently selected workspace.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductFeedback::class) ?? false;
    }

    /**
     * Validate the private feedback fields without accepting arbitrary request attributes.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(ProductFeedback::CATEGORIES)],
            'severity' => ['required', Rule::in(ProductFeedback::SEVERITIES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'reproduction_steps' => ['nullable', 'string', 'max:10000'],
            'page' => ['nullable', 'string', 'max:500', 'regex:/\A\/(?!\/)[^?#\r\n]*\z/'],
        ];
    }
}
