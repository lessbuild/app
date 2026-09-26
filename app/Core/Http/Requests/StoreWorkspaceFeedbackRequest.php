<?php

namespace App\Core\Http\Requests;

use App\Core\Enums\ProductKey;
use App\Core\Models\WorkspaceFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceFeedbackRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'product' => ['required', Rule::in(ProductKey::values())],
            'category' => ['required', Rule::in(WorkspaceFeedback::CATEGORIES)],
            'severity' => ['required', Rule::in(WorkspaceFeedback::SEVERITIES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'reproduction_steps' => ['nullable', 'string', 'max:10000'],
            'page' => ['nullable', 'string', 'max:500', 'regex:/\A\/(?!\/)[^?#\r\n]*\z/'],
        ];
    }
}
