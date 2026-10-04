<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Project;
use App\Models\Provider;
use App\Services\Deploy\GitHubApp;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/github-app/providers/{provider}/repositories`. */
final class ShowGitHubAppRepositoriesController
{
    /**
     * Return the repositories a GitHub App installation can reach, and the account's projects to connect one in.
     *
     * @param  Account  $account
     * @param  Provider  $provider
     * @param  GitHubApp  $github
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Provider $provider, GitHubApp $github): JsonResponse
    {
        abort_unless($provider->isGitHubApp(), 404);

        return response()->json([
            'provider' => ['id' => $provider->id, 'name' => $provider->name],
            'repositories' => $github->repositories((string) $provider->external_id),
            'projects' => $account->projects()->orderBy('name')->get(['id', 'name'])->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->values(),
        ]);
    }
}
