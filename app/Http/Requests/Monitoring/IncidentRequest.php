<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation and the assignee check happen in UpdateIncident. */
final class IncidentRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['acknowledge', 'note', 'assign'])],
            'version' => ['required', 'integer', 'min:0'],
            'assignee_id' => ['exclude_unless:action,assign', 'present', 'nullable', 'string', 'max:26'],
            'note' => ['required_if:action,note', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array{action: string, version: int, assignee_id?: string|null, note?: string|null} */
    public function details(): array
    {
        $data = $this->validated();

        return [
            'action' => (string) $data['action'],
            'version' => (int) $data['version'],
            ...(array_key_exists('assignee_id', $data) ? ['assignee_id' => is_string($data['assignee_id']) ? $data['assignee_id'] : null] : []),
            'note' => is_string($data['note'] ?? null) ? $data['note'] : null,
        ];
    }
}
