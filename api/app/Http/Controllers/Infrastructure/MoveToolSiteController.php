<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\MoveToolSite;
use App\Models\Project;
use App\Models\ToolMove;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MoveToolSiteController
{
    /**
     * Recreate one of the other tool's sites on a server here.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ToolMove  $move
     * @param  MoveToolSite  $moveSite
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ToolMove $move, MoveToolSite $moveSite): JsonResponse
    {
        $data = $request->validate(['site' => ['required', 'string', 'max:100'], 'server_id' => ['required', 'integer', 'min:1']]);
        $result = $moveSite->handle($user, $project->account, $move, $data['site'], (int) $data['server_id']);
        $status = __(':domain is being set up on its new server, with :tasks.', ['domain' => $result['website']->url, 'tasks' => trans_choice(':count cron job or daemon|:count cron jobs and daemons', $result['tasks'])]);
        if ($result['skipped'] !== []) {
            $status .= ' '.__('Add these yourself, as they didn’t fit: :commands', ['commands' => implode('; ', $result['skipped'])]);
        }

        return response()->json(['redirect' => route('infrastructure.moves', $project, false), 'message' => $status]);
    }
}
