<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreHeartbeatRequest extends FormRequest
{
    /**
     * Allowed once the heartbeat key middleware has identified the monitor.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->attributes->has('heartbeat_monitor_id');
    }

    /**
     * The JSON body, which the middleware decoded.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /**
     * A run UUID and the signal: start, success or failure.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return ['run_id' => ['required', 'string', 'uuid'], 'signal' => ['required', 'string', Rule::in(['start', 'success', 'failure'])]];
    }

    /**
     * Refuses any other field; times are assigned on receipt.
     *
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($validator->getData()), ['run_id', 'signal']) !== []) {
                $validator->errors()->add('payload', 'Only run_id and signal are accepted. Timestamps are assigned on receipt.');
            }
        }];
    }
}
