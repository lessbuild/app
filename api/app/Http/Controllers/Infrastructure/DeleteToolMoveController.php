<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteToolMove;
use App\Models\Project;
use App\Models\ToolMove;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteToolMoveController
{
    /**
     * Forget a move's token and what was read.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ToolMove  $move
     * @param  DeleteToolMove  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ToolMove $move, DeleteToolMove $delete): JsonResponse
    {
        $delete->handle($user, $project->account, $move);

        return response()->json(['redirect' => route('infrastructure.moves', $project, false), 'message' => __('The :tool token and what was read from it are gone.', ['tool' => $move->sourceName()])]);
    }
}
