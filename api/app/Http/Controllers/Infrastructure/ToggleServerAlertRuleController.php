<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ToggleServerAlertRule;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToggleServerAlertRuleController
{
    /**
     * Enable or disable a server alert rule.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ServerAlertRule  $rule
     * @param  ToggleServerAlertRule  $toggle
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerAlertRule $rule, ToggleServerAlertRule $toggle): JsonResponse
    {
        abort_unless($rule->account_id === $project->account_id && ($rule->server_id === null || $rule->server_id === $server->id), 404);
        $toggle->handle($project->account, $user, $rule, $request->boolean('enabled'));

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server], false), 'message' => __('Alert updated.')]);
    }
}
