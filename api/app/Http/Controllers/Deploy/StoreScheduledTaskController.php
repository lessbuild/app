<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveScheduledTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreScheduledTaskController
{
    /**
     * Add a scheduled task to an environment and return to its Automation tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveScheduledTask  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveScheduledTask $save): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'website_id' => ['required', 'integer'],
            'command' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B-\x1F]/'],
            'cron_expression' => ['required', 'string', 'max:100'], 'timezone' => ['required', 'string', 'max:64'],
            'timeout_seconds' => ['required', 'integer', 'between:10,3600'],
        ]);
        $save->handle($user, $environment, [
            'name' => (string) $data['name'], 'website_id' => (int) $data['website_id'], 'command' => (string) $data['command'],
            'cron_expression' => trim((string) $data['cron_expression']), 'timezone' => (string) $data['timezone'], 'timeout_seconds' => (int) $data['timeout_seconds'],
            'without_overlapping' => $request->boolean('without_overlapping'), 'alert_on_failure' => $request->boolean('alert_on_failure'), 'is_enabled' => true,
        ]);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'], false), 'message' => __('Scheduled task added.')]);
    }
}
