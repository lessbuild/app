<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use App\Enums\IssueStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchIssuesRequest extends FormRequest
{
    /**
     * Get the query string to validate.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['all', ...array_column(IssueStatus::cases(), 'value')])],
            'q' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'string', Rule::in(['debug', 'info', 'warning', 'error', 'critical'])],
            'environment' => ['nullable', 'string', 'max:36'],
            'ownership' => ['nullable', 'string', Rule::in(['any', 'mine', 'unassigned'])],
            'sort' => ['nullable', 'string', Rule::in(['impact', 'newest'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'events_page' => ['nullable', 'integer', 'between:1,100000'],
            'activity_page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }

    /**
     * Get the validated filters with empty ones dropped, over defaults of open issues assigned to anyone.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_replace(['status' => 'open', 'ownership' => 'any', 'sort' => 'impact'], array_filter($this->validated(), fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
