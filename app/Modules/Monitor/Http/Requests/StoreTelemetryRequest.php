<?php

namespace App\Modules\Monitor\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTelemetryRequest extends FormRequest
{
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
        return $this->json()->all();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'string', 'max:100'],
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.config('monitor.beacon.telemetry.max_events_per_batch')],
            'events.*' => ['required', 'array'],
            'events.*.id' => ['nullable', 'string', 'max:100'],
            'events.*.type' => ['required', 'string', Rule::in([
                'request',
                'query',
                'job',
                'exception',
                'log',
                'metric',
            ])],
            'events.*.severity' => ['nullable', 'string', Rule::in(['debug', 'info', 'warning', 'error', 'critical'])],
            'events.*.name' => ['nullable', 'string', 'max:255'],
            'events.*.title' => ['nullable', 'string', 'max:255'],
            'events.*.timestamp' => ['nullable', 'date'],
            'events.*.trace_id' => ['nullable', 'string', 'max:64'],
            'events.*.span_id' => ['nullable', 'string', 'max:32'],
            'events.*.parent_span_id' => ['nullable', 'string', 'max:32'],
            'events.*.route' => ['nullable', 'string', 'max:255'],
            'events.*.url' => ['nullable', 'string', 'max:2048'],
            'events.*.service' => ['nullable', 'string', 'max:100'],
            'events.*.status_code' => ['nullable', 'integer', 'between:100,599'],
            'events.*.duration_ms' => ['nullable', 'numeric', 'min:0', 'max:86400000'],
            'events.*.fingerprint' => ['nullable', 'string', 'max:64'],
            'events.*.affected_users' => ['nullable', 'integer', 'min:0'],
            'events.*.details' => ['nullable', 'string', 'max:10000'],
            'events.*.attributes' => ['nullable', 'array'],
            'events.*.payload' => ['nullable', 'array'],
        ];
    }
}
