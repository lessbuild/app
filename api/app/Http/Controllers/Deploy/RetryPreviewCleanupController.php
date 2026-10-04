<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RetryPreviewCleanup;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RetryPreviewCleanupController
{
    /**
     * Queue a failed preview cleanup again and return to the previews page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Preview  $preview
     * @param  RetryPreviewCleanup  $retry
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Preview $preview, RetryPreviewCleanup $retry): JsonResponse
    {
        $retry->handle($user, $preview);

        return response()->json(['redirect' => route('deploy.previews', $project, false), 'message' => __('Cleanup queued.')]);
    }
}
