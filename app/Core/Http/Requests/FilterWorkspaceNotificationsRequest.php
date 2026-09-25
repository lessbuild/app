<?php

namespace App\Core\Http\Requests;

use App\Core\Data\Notifications\WorkspaceNotificationSeverity;
use App\Core\Enums\ProductKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FilterWorkspaceNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return self::filterRules();
    }

    /** @return array<string, array<int, mixed>> */
    public static function filterRules(): array
    {
        return [
            'state' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'product' => ['nullable', Rule::in(['all', ...ProductKey::values()])],
            'severity' => ['nullable', Rule::in(['all', ...array_map(fn (WorkspaceNotificationSeverity $severity): string => $severity->value, WorkspaceNotificationSeverity::cases())])],
            'project' => ['nullable', 'string', 'max:26'],
        ];
    }
}
