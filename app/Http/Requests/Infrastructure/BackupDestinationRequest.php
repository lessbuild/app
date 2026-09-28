<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BackupDestinationRequest extends FormRequest
{
    /**
     * Get the validation rules for a destination's storage provider, HTTPS endpoint, bucket, region, keys and path
     * prefix. The bucket and prefix are limited to characters that are safe in the backup commands.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'storage_provider' => ['required', Rule::in(array_keys(BackupDestinationPresets::all()))],
            'endpoint' => ['nullable', 'url:https', 'max:255'],
            'bucket' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/i'],
            'region' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9-]+\z/i'],
            'access_key' => ['nullable', 'string', 'max:1000'],
            'secret_key' => ['nullable', 'string', 'max:1000'],
            'path_prefix' => ['required', 'string', 'max:120', 'regex:/\A[a-zA-Z0-9._\/-]+\z/'],
        ];
    }

    /**
     * Get the validated destination with optional fields as null.
     *
     * @return array{name: string, storage_provider: string, endpoint: string|null, bucket: string, region: string, access_key: string|null, secret_key: string|null, path_prefix: string}
     */
    public function destination(): array
    {
        /** @var array{name: string, storage_provider: string, endpoint?: string|null, bucket: string, region: string, access_key?: string|null, secret_key?: string|null, path_prefix: string} $data */
        $data = $this->validated();

        return [...$data, 'endpoint' => $data['endpoint'] ?? null, 'access_key' => $data['access_key'] ?? null, 'secret_key' => $data['secret_key'] ?? null];
    }
}
