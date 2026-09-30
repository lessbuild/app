<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ConnectToolMove;
use App\Models\Project;
use App\Models\ToolMove;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreToolMoveController
{
    /**
     * Read Laravel Forge or Ploi with the API token given.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ConnectToolMove  $connect
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ConnectToolMove $connect): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', Rule::in(array_keys(ToolMove::SOURCES))], 'token' => ['required', 'string', 'max:5000']]);
        $move = $connect->handle($user, $project->account, $data['source'], $data['token']);
        $sites = collect($move->inventory)->sum(fn (array $server): int => count($server['sites']));

        return to_route('infrastructure.moves', $project)->with('status', __('Read :servers and :sites from :tool.', [
            'servers' => trans_choice(':count server|:count servers', count($move->inventory)), 'sites' => trans_choice(':count site|:count sites', $sites), 'tool' => $move->sourceName(),
        ]));
    }
}
