<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure\Storage;

use App\Actions\Storage\AttachStorageBucket;
use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttachStorageBucketController
{
    /**
     * Give an environment the bucket's settings, then return to Storage.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $bucket
     * @param  AttachStorageBucket  $attach
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $bucket, AttachStorageBucket $attach): JsonResponse
    {
        $record = StorageBucket::query()->where('project_id', $project->id)->findOrFail((int) $bucket);
        $environment = $project->environments()->findOrFail((string) $request->input('environment_id'));
        $now = $attach->handle($user, $record, $environment);

        return response()->json(['redirect' => route('infrastructure.storage', $project, false), 'message' => $now
            ? __(':environment now has the bucket’s settings (AWS_*). They apply from the next deploy.', ['environment' => $environment->name])
            : __('The bucket’s settings for :environment are waiting for someone else’s approval.', ['environment' => $environment->name])]);
    }
}
