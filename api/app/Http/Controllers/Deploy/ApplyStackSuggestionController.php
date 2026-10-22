<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApplyStackSuggestion;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplyStackSuggestionController
{
    /**
     * Add what the repository's app needs to its environment (a worker, the scheduler, Redis or the database).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  ApplyStackSuggestion  $apply
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, ApplyStackSuggestion $apply): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'in:queue,scheduler,redis,database']]);
        $apply->handle($user, $repository, (string) $data['key']);

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository], false), 'message' => __('Added. It starts with the next deploy.')]);
    }
}
