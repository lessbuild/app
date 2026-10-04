<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ReviewBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewBuildController
{
    /**
     * Approve or rejects a deploy waiting for approval.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ReviewBuild  $review
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Build $build, ReviewBuild $review): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:1000']]);
        $review->handle($user, $build, $data['decision'] === 'approve', $data['note'] ?? null);

        return response()->json(['redirect' => route('deploy.builds.show', [$project, $build->id], false), 'message' => $data['decision'] === 'approve' ? __('Approved; the deploy is starting.') : __('Deploy rejected.')]);
    }
}
