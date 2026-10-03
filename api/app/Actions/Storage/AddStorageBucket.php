<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use App\Services\Storage\BucketManager;
use App\Services\Storage\S3Location;
use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AddStorageBucket
{
    /**
     * Create a new AddStorageBucket instance.
     *
     * @param  BucketManager  $buckets  Creates or checks the bucket.
     */
    public function __construct(private readonly BucketManager $buckets) {}

    /**
     * Add an S3-compatible bucket to a project: create it with the given keys, or check that they reach an existing
     * one. Nothing is saved when the storage service refuses.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  array<string, mixed>  $input
     * @return StorageBucket
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Project $project, array $input): StorageBucket
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'infrastructure']);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:60'],
            'storage_provider' => ['required', Rule::in(array_keys(BackupDestinationPresets::all()))],
            'region' => ['required', 'string', 'max:60', 'regex:/\A[a-z0-9-]+\z/i'],
            'endpoint' => ['nullable', 'string', 'max:255', 'url:https'],
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/'],
            'access_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:255'],
            'create' => ['nullable', 'boolean'],
        ])->validate();
        $endpoint = BackupDestinationPresets::endpoint((string) $data['storage_provider'], (string) $data['region'], isset($data['endpoint']) ? (string) $data['endpoint'] : null);
        if (! is_string($endpoint) || $endpoint === '') {
            throw ValidationException::withMessages(['endpoint' => __('Enter the storage service’s endpoint.')]);
        }
        if (StorageBucket::query()->where('project_id', $project->id)->where('endpoint', rtrim($endpoint, '/'))->where('bucket', $data['bucket'])->exists()) {
            throw ValidationException::withMessages(['bucket' => __('This bucket is already in the project.')]);
        }
        $location = new S3Location(rtrim($endpoint, '/'), (string) $data['region'], (string) $data['bucket'], (string) $data['access_key'], (string) $data['secret_key']);
        try {
            ($data['create'] ?? false)
                ? $this->buckets->create($location, $data['storage_provider'] === 'amazon_s3')
                : $this->buckets->check($location);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['bucket' => __('The storage service refused: :error', ['error' => $exception->getMessage()])]);
        }
        $bucket = new StorageBucket;
        $bucket->forceFill([
            'project_id' => $project->id, 'name' => trim((string) $data['name']), 'storage_provider' => $data['storage_provider'], 'endpoint' => $location->endpoint,
            'region' => $location->region, 'bucket' => $location->bucket, 'access_key' => $location->accessKey, 'secret_key' => $location->secretKey,
            'verified_at' => now(), 'created_by' => $actor->id,
        ])->save();

        return $bucket;
    }
}
