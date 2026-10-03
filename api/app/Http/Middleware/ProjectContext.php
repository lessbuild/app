<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Accounts\SwitchAccount;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For /projects/{project}/… routes: people outside the project's account get a 404 (so project IDs
 * don't leak), and opening a project from another of your accounts makes that account current.
 */
final class ProjectContext
{
    /**
     * Create a new ProjectContext instance.
     *
     * Scopes project routes to the people who may see them.
     *
     * @param  SwitchAccount  $switchAccount  Switches to the project's account when it isn't the current one.
     */
    public function __construct(private readonly SwitchAccount $switchAccount) {}

    /**
     * Scope the request to its project: 404 people who may not see it, and make its account current when it isn't.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        $user = $request->user();
        abort_unless($project instanceof Project && $user instanceof User && $user->can('view', $project), 404);

        if ($user->current_account_id !== $project->account_id) {
            $this->switchAccount->handle($user, $project->account);
        }

        return $next($request);
    }
}
