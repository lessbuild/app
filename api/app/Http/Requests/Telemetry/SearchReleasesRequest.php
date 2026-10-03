<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchReleasesRequest extends FormRequest
{
    public const RANGES = ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];

    public const WINDOWS = [15 => '15 minutes', 60 => '1 hour', 360 => '6 hours', 1440 => '24 hours'];

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
     * Get the validation rules for the release pages' search, environment, baseline, range, comparison window and the
     * page numbers of each list.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'size:26'],
            'baseline' => ['nullable', 'integer', 'min:1'],
            'range' => ['nullable', 'string', Rule::in(array_keys(self::RANGES))],
            'window' => ['nullable', 'integer', Rule::in(array_keys(self::WINDOWS))],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'events_page' => ['nullable', 'integer', 'between:1,100000'],
            'issues_page' => ['nullable', 'integer', 'between:1,100000'],
            'deployments_page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }

    /**
     * Get the validated filters with empty ones dropped, over defaults of the last day and a 60-minute window.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_replace(['range' => '24h', 'window' => 60], array_filter($this->validated(), fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
