<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\VerifyDomain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class VerifyDomainController
{
    /**
     * Look for the domain's TXT record now and says whether it was found.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $domain
     * @param  VerifyDomain  $verify
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $domain, VerifyDomain $verify): JsonResponse
    {
        $target = $project->domains()->findOrFail($domain);

        return $verify->handle($user, $target)
            ? response()->json(['redirect' => route('projects.domains', $project, false), 'message' => __(':domain is verified.', ['domain' => $target->displayName()])])
            : response()->json(['redirect' => route('projects.domains', $project, false), 'message' => __('We couldn’t find the TXT record for :domain yet. DNS changes can take a while; check again later.', ['domain' => $target->displayName()])]);
    }
}
