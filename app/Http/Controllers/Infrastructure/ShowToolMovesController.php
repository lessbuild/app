<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ToolMove;
use App\Models\User;
use App\Models\Website;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowToolMovesController
{
    /**
     * Show the move from Laravel Forge or Ploi: connect a token, then what was read and which sites have moved.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $moves = ToolMove::query()->where('account_id', $project->account_id)->orderBy('source')->get();
        $movedIds = $moves->flatMap(fn (ToolMove $move): array => array_values($move->moved ?? []))->all();

        return view('infrastructure.moves', [
            'overview' => $overview->handle($project, $user),
            'moves' => $moves,
            'websites' => Website::query()->whereIn('id', $movedIds)->get()->keyBy('id'),
            'servers' => Server::query()->where('account_id', $project->account_id)->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get(),
        ]);
    }
}
