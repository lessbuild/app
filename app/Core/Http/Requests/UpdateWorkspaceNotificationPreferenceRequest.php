<?php

namespace App\Core\Http\Requests;

use App\Core\Data\Notifications\WorkspaceNotificationSeverity;
use App\Core\Enums\ProductKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWorkspaceNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'string', 'max:26'],
            'product' => ['required', Rule::in(ProductKey::values())],
            'severity' => ['required', Rule::enum(WorkspaceNotificationSeverity::class)],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
