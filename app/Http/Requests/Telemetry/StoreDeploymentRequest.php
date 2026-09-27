<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use App\Data\Telemetry\ReleaseIdentity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeploymentRequest extends FormRequest
{
    /**
     * The JSON body for API calls, the form fields otherwise.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /**
     * A deployment report: a UUID to deduplicate retries, release labels that are safe to store, an optional commit and
     * note, and an ISO 8601 time between 2000 and five minutes from now.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $label = function (string $attribute, mixed $value, Closure $fail): void {
            if (ReleaseIdentity::from('version', $attribute === 'service' ? $value : null, $attribute === 'service_namespace' ? $value : null) === null
                || ($attribute === 'version' && ReleaseIdentity::from($value) === null)) {
                $fail('Use a non-redacted release label without control characters.');
            }
        };

        return [
            'deployment_id' => ['required', 'string', 'uuid'],
            'version' => ['bail', 'required', 'string', 'max:128', $label],
            'service' => ['bail', 'nullable', 'string', 'max:100', $label],
            'service_namespace' => ['bail', 'nullable', 'string', 'max:100', $label],
            'commit_sha' => ['nullable', 'string', 'regex:/\\A[0-9a-fA-F]{7,64}\\z/'],
            'note' => ['nullable', 'string', 'max:1000'],
            'deployed_at' => [
                'bail', 'nullable', 'string',
                'regex:/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-](?:0\\d|1[0-4]):[0-5]\\d)\\z/',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parsed = date_parse($value);
                    if ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0) {
                        $fail('The deployed at field must be a valid date.');
                    }
                },
                'after_or_equal:2000-01-01T00:00:00Z',
                'before_or_equal:'.CarbonImmutable::now('UTC')->addMinutes(5)->toIso8601String(),
            ],
        ];
    }

    /**
     * Messages that say how to fix each field, since pipelines read them.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deployment_id.required' => 'Supply a deployment UUID and reuse it when retrying.',
            'deployment_id.uuid' => 'The deployment ID must be a valid UUID.',
            'commit_sha.regex' => 'Use a hexadecimal commit ID between 7 and 64 characters.',
            'deployed_at.regex' => 'Use an ISO 8601 timestamp with seconds and a timezone, such as 2026-09-21T12:00:00Z.',
            'deployed_at.after_or_equal' => 'The deployment time must be on or after 2000-01-01 UTC.',
            'deployed_at.before_or_equal' => 'The deployment time cannot be more than five minutes in the future.',
        ];
    }

    /**
     * The validated report with every optional field as a string or null.
     *
     * @return array{deployment_id: string, version: string, service?: string|null, service_namespace?: string|null, commit_sha?: string|null, note?: string|null, deployed_at?: string|null}
     */
    public function deployment(): array
    {
        $data = $this->validated();
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return [
            'deployment_id' => (string) $data['deployment_id'],
            'version' => (string) $data['version'],
            'service' => $text($data['service'] ?? null),
            'service_namespace' => $text($data['service_namespace'] ?? null),
            'commit_sha' => $text($data['commit_sha'] ?? null),
            'note' => $text($data['note'] ?? null),
            'deployed_at' => $text($data['deployed_at'] ?? null),
        ];
    }
}
