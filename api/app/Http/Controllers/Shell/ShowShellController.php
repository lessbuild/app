<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shell;

use App\Actions\Accounts\SwitchAccount;
use App\Models\Project;
use App\Models\User;
use App\Queries\Shell\ShellQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** `GET /api/app/shell?project=&service=&area=`. */
final class ShowShellController
{
    /**
     * Return the frame the app draws around a page: who's signed in, the switchers, and the navigation for the
     * project, service or area the page is in. Opening a project from another of the person's accounts makes that
     * account current; a project they can't see is a 404, so project IDs don't leak.
     *
     * @param  User  $user
     * @param  Request  $request
     * @param  ShellQuery  $shell
     * @param  SwitchAccount  $switchAccount
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Request $request, ShellQuery $shell, SwitchAccount $switchAccount): JsonResponse
    {
        $data = $request->validate([
            'project' => ['nullable', 'string', 'max:40'],
            'service' => ['nullable', 'string', 'max:40'],
            'area' => ['nullable', Rule::in(ShellQuery::AREAS)],
        ]);
        $project = null;
        if (is_string($data['project'] ?? null)) {
            $project = Project::query()->find($data['project']);
            abort_unless($project instanceof Project && $user->can('view', $project), 404);
            if ($user->current_account_id !== $project->account_id) {
                $switchAccount->handle($user, $project->account);
            }
        }

        return response()->json($shell->handle($user->refresh(), $project, $data['service'] ?? null, $data['area'] ?? null));
    }
}
