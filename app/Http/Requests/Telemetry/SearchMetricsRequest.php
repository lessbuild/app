<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchMetricsRequest extends FormRequest
{
    public const RANGES = ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days'];

    public const KINDS = ['gauge' => 'Gauge', 'sum' => 'Sum', 'histogram' => 'Histogram', 'exponentialHistogram' => 'Exponential histogram', 'summary' => 'Summary'];

    /**
     * The query string.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /**
     * The metrics pages' search, environment, kind, range, value-or-rate mode and page.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:26'],
            'kind' => ['nullable', Rule::in([...array_keys(self::KINDS), 'unsupported'])],
            'range' => ['nullable', Rule::in(array_keys(self::RANGES))],
            'mode' => ['nullable', Rule::in(['value', 'rate'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }

    /**
     * The validated filters with empty ones dropped, over defaults of the last hour showing values.
     *
     * @return array{range: string, mode: string, q?: string, environment?: string, kind?: string, page?: int}
     */
    public function filters(): array
    {
        $filters = ['range' => '1h', 'mode' => 'value'];
        foreach ($this->validated() as $key => $value) {
            if ($value !== null && $value !== '') {
                $filters[$key] = $key === 'page' ? (int) $value : (string) $value;
            }
        }

        /** @var array{range: string, mode: string, q?: string, environment?: string, kind?: string, page?: int} $filters */
        return $filters;
    }
}
