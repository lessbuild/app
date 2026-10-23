<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Provider;
use App\Models\User;
use App\Services\Deploy\LaunchPlanner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PlanLaunchController
{
    /**
     * Read the repository and propose how to launch it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  LaunchPlanner  $planner
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, LaunchPlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'integer'],
            'url' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:200'],
            'domain' => ['required', 'string', 'max:200', 'regex:/\A([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/i'],
            'cloud_id' => ['nullable', 'integer'],
            'region' => ['nullable', 'string', 'max:100'],
        ]);
        $provider = Provider::query()->where('account_id', $project->account_id)->find((int) $data['provider_id']);
        if ($provider === null || ! $provider->type->isSourceControl()) {
            throw ValidationException::withMessages(['provider_id' => __('Choose a GitHub, GitLab or Bitbucket provider from this account.')]);
        }
        $cloud = isset($data['cloud_id']) ? Provider::query()->where('account_id', $project->account_id)->find((int) $data['cloud_id']) : null;

        return response()->json(['plan' => $planner->plan($project, $provider, (string) $data['url'], (string) $data['branch'], (string) $data['domain'],
            $cloud !== null && $cloud->type->hostsServers() ? $cloud : null, $data['region'] ?? null, $user)]);
    }
}
