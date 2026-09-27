<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\RemoveDomain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RemoveDomainController
{
    /**
     * Removes a domain from the project.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $domain, RemoveDomain $remove): RedirectResponse
    {
        $target = $project->domains()->findOrFail($domain);
        $remove->handle($user, $target);

        return to_route('projects.domains', $project)->with('status', __(':domain removed.', ['domain' => $target->displayName()]));
    }
}
