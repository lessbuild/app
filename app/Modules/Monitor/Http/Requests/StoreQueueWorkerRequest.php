<?php

namespace App\Modules\Monitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQueueWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('queue_monitor_id');
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['worker_id' => ['required', 'string', 'uuid'], 'sequence' => ['required', 'integer:strict', 'between:1,2147483647'],
            'status' => ['required', 'string', Rule::in(['idle', 'busy', 'stopped'])],
            'job_id' => ['required_if:status,busy', 'prohibited_unless:status,busy', 'nullable', 'string', 'uuid']];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($validator->getData()), ['worker_id', 'sequence', 'status', 'job_id']) !== []) {
                $validator->errors()->add('payload', 'Only worker_id, sequence, status and job_id are accepted. Times are assigned on receipt.');
            }
        }];
    }
}
