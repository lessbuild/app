<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveServerAlertRule;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreServerAlertRuleController
{
    /**
     * Add an alert rule for this server or every server.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  SaveServerAlertRule  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, SaveServerAlertRule $save): JsonResponse
    {
        /** @var array{name: string, metric: string, operator: string, threshold: string, consecutive_breaches: string, cooldown_minutes: string, scope: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'metric' => ['required', Rule::in(array_keys(ServerAlertRule::METRICS))],
            'operator' => ['required', Rule::in(['gte', 'lte'])],
            'threshold' => ['required', 'numeric', 'between:0,99999999'],
            'consecutive_breaches' => ['required', 'integer', 'between:1,20'],
            'cooldown_minutes' => ['required', 'integer', 'between:5,1440'],
            'scope' => ['required', Rule::in(['server', 'account'])],
        ]);
        $save->handle($project->account, $user, $data['scope'] === 'server' ? $server : null, $data);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'alerts'], false), 'message' => __('Alert added.')]);
    }
}
