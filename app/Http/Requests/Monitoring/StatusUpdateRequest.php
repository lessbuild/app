<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Models\StatusUpdate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Times are UTC (datetime-local inputs). Authorisation happens in SaveStatusUpdate. */
final class StatusUpdateRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(StatusUpdate::KINDS)],
            'status' => ['required', Rule::in(array_merge(...array_values(StatusUpdate::STATUSES)))],
            'severity' => ['required', Rule::in(StatusUpdate::SEVERITIES)],
            'title' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'message' => ['required', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'remediation' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\\TH:i', 'after:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = $this->input('kind');
            if ($validator->errors()->isEmpty() && is_string($kind) && ! in_array($this->input('status'), StatusUpdate::STATUSES[$kind] ?? [], true)) {
                $validator->errors()->add('status', __('Choose a status that matches the type of update.'));
            }
        });
    }
}
