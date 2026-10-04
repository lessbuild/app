<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ShowServerCommandsController
{
    /**
     * List the commands run on a server, newest first (`?status=` filters, `?cursor=` pages), with one command's
     * output when `?output=` names it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ProjectOverviewQuery $overview): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([...ServerCommandExecution::ACTIVE, ...ServerCommandExecution::FINISHED])], 'output' => ['nullable', 'integer']]);
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : null;
        $executions = $server->commandExecutions()->with('user')->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')->cursorPaginate(25)->withQueryString();
        $selected = isset($filters['output']) ? $server->commandExecutions()->find((int) $filters['output']) : null;

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'server' => ['id' => $server->id, 'label' => $server->label(), 'active' => $server->provisioning_status === Server::STATUS_ACTIVE],
            'executions' => collect($executions->items())->map(fn (ServerCommandExecution $execution): array => [
                'id' => $execution->id,
                'command' => $execution->command,
                'status' => $execution->status,
                'exitCode' => $execution->exit_code,
                'finished' => $execution->isFinished(),
                'user' => $execution->user?->name,
                'createdAt' => $execution->created_at?->toIso8601String(),
            ])->values(),
            'nextCursor' => $executions->nextCursor()?->encode(),
            'previousCursor' => $executions->previousCursor()?->encode(),
            'status' => $status,
            'statuses' => [...ServerCommandExecution::ACTIVE, ...ServerCommandExecution::FINISHED],
            'selected' => $selected instanceof ServerCommandExecution ? [
                'id' => $selected->id, 'command' => $selected->command, 'status' => $selected->status, 'exitCode' => $selected->exit_code, 'output' => $selected->output,
            ] : null,
            'timeoutSeconds' => (int) config('infrastructure.ssh_command_timeout'),
            'retentionDays' => (int) config('infrastructure.server_command_retention_days'),
            'canManage' => $user->can('runCommands', $server),
        ]);
    }
}
