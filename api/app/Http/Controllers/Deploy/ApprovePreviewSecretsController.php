<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApprovePreviewSecrets;
use App\Http\Requests\Deploy\PreviewSecretsRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ApprovePreviewSecretsController
{
    /**
     * Approve the chosen secrets for the preview's reviewed revision and return to the previews page.
     *
     * @param  PreviewSecretsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Preview  $preview
     * @param  ApprovePreviewSecrets  $approve
     * @return JsonResponse
     */
    public function __invoke(PreviewSecretsRequest $request, #[CurrentUser] User $user, Project $project, Preview $preview, ApprovePreviewSecrets $approve): JsonResponse
    {
        $approve->handle($user, $preview, $request->revision(), $request->keys());

        return response()->json(['redirect' => route('deploy.previews', $project, false), 'message' => __('Secrets approved for this revision. The preview is deploying again with them.')]);
    }
}
