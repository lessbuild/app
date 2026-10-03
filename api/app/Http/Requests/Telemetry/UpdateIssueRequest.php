<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateIssueRequest extends FormRequest
{
    public const ACTIONS = ['resolve', 'reopen', 'snooze', 'ignore', 'assign'];

    public const SNOOZE_MINUTES = [15 => '15 minutes', 60 => '1 hour', 1440 => '1 day', 10080 => '7 days'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'version' => ['required', 'integer', 'min:0'],
            'snooze_minutes' => ['exclude_unless:action,snooze', 'required', 'integer', Rule::in(array_keys(self::SNOOZE_MINUTES))],
            'assignee_id' => ['exclude_unless:action,assign', 'present', 'nullable', 'string', 'max:26'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
