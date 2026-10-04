<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ClosePreview;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ClosePreviewController
{
    /**
     * Close a preview now and return to the previews page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Preview  $preview
     * @param  ClosePreview  $close
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Preview $preview, ClosePreview $close): JsonResponse
    {
        $close->handle($user, $preview);

        return response()->json(['redirect' => route('deploy.previews', $project, false), 'message' => __('Preview closed. Its website is being removed.')]);
    }
}
