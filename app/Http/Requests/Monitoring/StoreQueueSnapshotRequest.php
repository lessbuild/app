<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Support\Monitoring\QueueMonitorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreQueueSnapshotRequest extends FormRequest
{
    /**
     * Allowed once the queue key middleware has identified the monitor.
     */
    public function authorize(): bool
    {
        return $this->attributes->has('queue_monitor_id');
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
     * A snapshot UUID, a UTC sample time, and the queue metrics as strict integers (only `pending` is required).
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = ['snapshot_id' => ['required', 'string', 'uuid'],
            'observed_at' => ['required', 'string', 'date_format:Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.v\Z,Y-m-d\TH:i:s.u\Z']];
        foreach (QueueMonitorSettings::METRICS as $metric) {
            $rules[$metric] = [$metric === 'pending' ? 'required' : 'nullable', 'integer:strict', 'between:0,1000000000'];
        }

        return $rules;
    }

    /**
     * Refuses fields beyond the documented ones (so job payloads and credentials are never stored), sample times before
     * 2000 or more than 30 seconds ahead, and a positive oldest wait on an empty queue.
     *
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();
            if (array_diff(array_keys($data), ['snapshot_id', 'observed_at', ...QueueMonitorSettings::METRICS]) !== []) {
                $validator->errors()->add('payload', 'Only the documented snapshot fields are accepted. Do not send job payloads or credentials.');
            }
            if (! $validator->errors()->has('observed_at')) {
                $observed = CarbonImmutable::parse($data['observed_at'], 'UTC');
                if ($observed->lt('2000-01-01 00:00:00 UTC') || $observed->gt(CarbonImmutable::now('UTC')->addSeconds(30))) {
                    $validator->errors()->add('observed_at', 'Use a UTC sample timestamp from 2000 onward and no more than 30 seconds in the future.');
                }
            }
            if (! $validator->errors()->hasAny(['pending', 'oldest_wait_seconds']) && ($data['pending'] ?? null) === 0
                && ($data['oldest_wait_seconds'] ?? 0) > 0) {
                $validator->errors()->add('oldest_wait_seconds', 'An empty ready queue cannot have a positive oldest-job wait.');
            }
        }];
    }
}
