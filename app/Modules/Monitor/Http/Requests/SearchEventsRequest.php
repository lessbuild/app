<?php

namespace App\Modules\Monitor\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchEventsRequest extends FormRequest
{
    public const TYPES = [
        'request' => 'Requests', 'query' => 'Queries', 'job' => 'Jobs',
        'exception' => 'Exceptions', 'log' => 'Logs', 'metric' => 'Metrics',
    ];

    public const SEVERITIES = ['debug' => 'Debug', 'info' => 'Info', 'warning' => 'Warning', 'error' => 'Error', 'critical' => 'Critical'];

    public const RANGES = ['15m' => 'Last 15 minutes', '1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'custom' => 'Custom UTC range', 'all' => 'All stored events'];

    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'slowest' => 'Longest duration first'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'application' => ['nullable', 'integer', 'min:1'],
            'environment' => ['nullable', 'integer', 'min:1'],
            'release' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', Rule::in(array_keys(self::TYPES))],
            'severity' => ['nullable', 'string', Rule::in(array_keys(self::SEVERITIES))],
            'service' => ['nullable', 'string', 'max:100'],
            'trace' => ['nullable', 'string', 'max:64'],
            'has_trace' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'status' => ['nullable', 'integer', 'between:100,599'],
            'min_duration' => ['nullable', 'numeric', 'min:0', 'max:100000000000000'],
            'range' => ['nullable', 'string', Rule::in(array_keys(self::RANGES))],
            'from' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d\TH:i'],
            'to' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d\TH:i', 'after:from'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_replace(['range' => '24h', 'sort' => 'newest'], array_filter(
            $this->validated(),
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }
}
