<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\RemoveDomain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RemoveDomainController
{
    /**
     * Remove a domain from the project.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $domain
     * @param  RemoveDomain  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $domain, RemoveDomain $remove): JsonResponse
    {
        $target = $project->domains()->findOrFail($domain);
        $remove->handle($user, $target);

        return response()->json(['redirect' => route('projects.domains', $project, false), 'message' => __(':domain removed.', ['domain' => $target->displayName()])]);
    }
}
