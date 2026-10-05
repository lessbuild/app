<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateBuildNote;
use App\Models\Build;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PUT /api/app/projects/{project}/deploy/builds/{build}/note`. */
final class UpdateBuildNoteController
{
    /**
     * Save a note on a deploy (the route lets only people who can deploy its repository in).
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  Build  $build
     * @param  UpdateBuildNote  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, Build $build, UpdateBuildNote $update): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $update->handle($build, is_string($note) ? $note : null);

        return response()->json(['message' => __('Note saved.')]);
    }
}
