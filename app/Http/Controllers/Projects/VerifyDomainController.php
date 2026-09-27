<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\VerifyDomain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class VerifyDomainController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $domain, VerifyDomain $verify): RedirectResponse
    {
        $target = $project->domains()->findOrFail($domain);

        return $verify->handle($user, $target)
            ? to_route('projects.domains', $project)->with('status', __(':domain is verified.', ['domain' => $target->displayName()]))
            : to_route('projects.domains', $project)->with('notice', __('We couldn’t find the TXT record for :domain yet. DNS changes can take a while; check again later.', ['domain' => $target->displayName()]));
    }
}
