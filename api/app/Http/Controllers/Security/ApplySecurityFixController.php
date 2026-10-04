<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\ApplySecurityFix;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ApplySecurityFixController
{
    /**
     * Apply a finding's one-click fix and go back. Another project's findings are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $finding
     * @param  ApplySecurityFix  $apply
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $finding, ApplySecurityFix $apply): JsonResponse
    {
        $apply->handle($user, SecurityFinding::query()->where('project_id', $project->id)->findOrFail($finding));

        return response()->json(['message' => __('Applying the fix. The server is checked again once it’s done.')]);
    }
}
