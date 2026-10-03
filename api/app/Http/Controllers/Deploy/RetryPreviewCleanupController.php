<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RetryPreviewCleanup;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryPreviewCleanupController
{
    /**
     * Queue a failed preview cleanup again and return to the previews page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Preview  $preview
     * @param  RetryPreviewCleanup  $retry
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Preview $preview, RetryPreviewCleanup $retry): RedirectResponse
    {
        $retry->handle($user, $preview);

        return to_route('deploy.previews', $project)->with('status', __('Cleanup queued.'));
    }
}
