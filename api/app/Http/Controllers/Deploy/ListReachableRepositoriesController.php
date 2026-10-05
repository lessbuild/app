<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Provider;
use App\Services\Deploy\GitRepositories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class ListReachableRepositoriesController
{
    /**
     * Return the repositories one of the account's Git providers can reach, to pick one when connecting it to the
     * project. When the provider can't be listed, or refuses, say so; the address can still be typed in.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  GitRepositories  $repositories
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, GitRepositories $repositories): JsonResponse
    {
        $provider = Provider::query()->where('account_id', $project->account_id)->findOrFail($request->integer('provider'));
        if (! $repositories->canList($provider)) {
            return response()->json(['repositories' => [], 'error' => __('This provider’s repositories can’t be listed. Type the repository’s address instead.')]);
        }
        try {
            return response()->json(['repositories' => $repositories->list($provider), 'error' => null]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['repositories' => [], 'error' => __(':provider didn’t list its repositories. Check its token under Account → Providers, or type the address.', ['provider' => $provider->name])]);
        }
    }
}
