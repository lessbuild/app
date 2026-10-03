<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure\Storage;

use App\Actions\Storage\RemoveStorageBucket;
use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteStorageBucketController
{
    /**
     * Forget a bucket (its files stay at the storage service), then return to Storage.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $bucket
     * @param  RemoveStorageBucket  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $bucket, RemoveStorageBucket $remove): RedirectResponse
    {
        $remove->handle($user, StorageBucket::query()->where('project_id', $project->id)->findOrFail((int) $bucket));

        return to_route('infrastructure.storage', $project)->with('status', __('Removed from the project. The bucket and its files are still at the storage service.'));
    }
}
