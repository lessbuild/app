<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Data\Telemetry\ReleaseIdentity;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDeploymentRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $workspace): bool
    {
        $environment = $this->route('environment');
        abort_unless($environment instanceof Environment && Environment::forWorkspace($workspace->get())->visibleTo($this->user(), $workspace->get())->whereKey($environment->id)->exists(), 404);
        Gate::authorize('create', [Deployment::class, $environment]);

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /** @return array<string, array<mixed>> */
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

    /** @return array<string, string> */
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
}
