<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\StartLaunch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreLaunchController
{
    /**
     * Start launching a repository as planned and chosen on the Launch page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  StartLaunch  $start
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, StartLaunch $start): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'integer'],
            'url' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:200'],
            'domain' => ['required', 'string', 'max:200'],
            'server' => ['required', 'array'],
            'server.mode' => ['required', 'in:existing,new'],
            'server.server_id' => ['nullable', 'integer'],
            'server.provider_id' => ['nullable', 'integer'],
            'server.region' => ['nullable', 'string', 'max:100'],
            'server.size' => ['nullable', 'string', 'max:100'],
            'server.image' => ['nullable', 'string', 'max:100'],
            'apps' => ['required', 'array', 'max:10'],
            'apps.*.name' => ['nullable', 'string', 'max:255'],
            'apps.*.website_id' => ['nullable', 'integer'],
            'apps.*.domain' => ['nullable', 'string', 'max:253'],
            'apps.*.environment' => ['nullable', 'string', 'max:60'],
            'apps.*.mysql' => ['nullable', 'boolean'],
            'apps.*.redis' => ['nullable', 'boolean'],
            'apps.*.queue' => ['nullable', 'boolean'],
            'apps.*.scheduler' => ['nullable', 'boolean'],
            'apps.*.githubActions' => ['nullable', 'boolean'],
            'apps.*.secretValues' => ['nullable', 'array'],
            'apps.*.secretValues.*' => ['nullable', 'string', 'max:5000'],
        ]);
        $launch = $start->handle($user, $project, $data);

        return response()->json(['redirect' => route('deploy.launches.show', [$project, $launch], false), 'message' => __('Launching. This page follows it.')]);
    }
}
