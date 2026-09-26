<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accounts\Actions\SwitchAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For /projects/{project}/… routes: people outside the project's account get a 404 (so project IDs
 * don't leak), and opening a project from another of your accounts makes that account current.
 */
final class ProjectContext
{
    public function __construct(private readonly SwitchAccount $switchAccount) {}

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
