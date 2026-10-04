<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure\Storage;

use App\Actions\Storage\AddStorageBucket;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreStorageBucketController
{
    /**
     * Add (and optionally create) a bucket, then return to Storage.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AddStorageBucket  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AddStorageBucket $add): JsonResponse
    {
        $bucket = $add->handle($user, $project, [...$request->only(['name', 'storage_provider', 'region', 'endpoint', 'bucket', 'access_key', 'secret_key']), 'create' => $request->boolean('create')]);

        return response()->json(['redirect' => route('infrastructure.storage', $project, false), 'message' => __('The bucket :bucket is ready. Attach it to an environment to use it.', ['bucket' => $bucket->bucket])]);
    }
}
