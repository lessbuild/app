<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\LiftSecurityBlock;
use App\Models\Project;
use App\Models\SecurityBlock;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteSecurityBlockController
{
    /**
     * Unblock an address now and send the app back to the attacks page. Another project's blocks are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $block
     * @param  LiftSecurityBlock  $lift
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $block, LiftSecurityBlock $lift): JsonResponse
    {
        $lift->handle($user, SecurityBlock::query()->where('project_id', $project->id)->findOrFail($block));

        return response()->json(['redirect' => route('security.attacks', $project, false), 'message' => __('Unblocked.')]);
    }
}
