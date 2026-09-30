<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteToolMove;
use App\Models\Project;
use App\Models\ToolMove;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteToolMoveController
{
    /**
     * Forget a move's token and what was read.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ToolMove  $move
     * @param  DeleteToolMove  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ToolMove $move, DeleteToolMove $delete): RedirectResponse
    {
        $delete->handle($user, $project->account, $move);

        return to_route('infrastructure.moves', $project)->with('status', __('The :tool token and what was read from it are gone.', ['tool' => $move->sourceName()]));
    }
}
