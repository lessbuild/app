<?php

namespace App\Modules\Monitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('heartbeat_monitor_id');
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['run_id' => ['required', 'string', 'uuid'], 'signal' => ['required', 'string', Rule::in(['start', 'success', 'failure'])]];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($validator->getData()), ['run_id', 'signal']) !== []) {
                $validator->errors()->add('payload', 'Only run_id and signal are accepted. Timestamps are assigned on receipt.');
            }
        }];
    }
}
