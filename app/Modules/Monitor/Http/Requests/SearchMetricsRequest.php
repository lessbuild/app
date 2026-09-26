<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchMetricsRequest extends FormRequest
{
    public const RANGES = ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days'];

    public function authorize(CurrentWorkspace $workspace): bool
    {
        if ($series = $this->route('metricSeries')) {
            abort_unless($series instanceof MetricSeries && MetricSeries::forWorkspace($workspace->get())->visibleTo($this->user(), $workspace->get())->whereKey($series->id)->exists(), 404);
            Gate::authorize('view', $series);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', Rule::in(['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary', 'unsupported'])],
            'range' => ['nullable', Rule::in(array_keys(self::RANGES))],
            'mode' => ['nullable', Rule::in(['value', 'rate'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_replace(['range' => '1h', 'mode' => 'value'], array_filter($this->validated(), fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
