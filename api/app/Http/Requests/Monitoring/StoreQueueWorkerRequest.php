<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreQueueWorkerRequest extends FormRequest
{
    /**
     * Allow the request once the queue key middleware has identified the monitor.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->attributes->has('queue_monitor_id');
    }

    /**
     * Get the JSON body to validate, which the middleware decoded.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /**
     * Get the validation rules: a worker UUID, an increasing sequence number, its status, and a job UUID exactly when
     * it's busy.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return ['worker_id' => ['required', 'string', 'uuid'], 'sequence' => ['required', 'integer:strict', 'between:1,2147483647'],
            'status' => ['required', 'string', Rule::in(['idle', 'busy', 'stopped'])],
            'job_id' => ['required_if:status,busy', 'prohibited_unless:status,busy', 'nullable', 'string', 'uuid']];
    }

    /**
     * Refuse any other field; times are assigned on receipt.
     *
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($validator->getData()), ['worker_id', 'sequence', 'status', 'job_id']) !== []) {
                $validator->errors()->add('payload', 'Only worker_id, sequence, status and job_id are accepted. Times are assigned on receipt.');
            }
        }];
    }

    /**
     * Get the validated heartbeat with the job ID only when there is one.
     *
     * @return array{worker_id: string, sequence: int, status: string, job_id?: ?string}
     */
    public function details(): array
    {
        $data = $this->validated();

        return [
            'worker_id' => (string) $data['worker_id'],
            'sequence' => (int) $data['sequence'],
            'status' => (string) $data['status'],
            ...(is_string($data['job_id'] ?? null) ? ['job_id' => $data['job_id']] : []),
        ];
    }
}
