<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\AddDomain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AddDomainController
{
    /**
     * Add a domain to the project and shows the TXT record to publish.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AddDomain  $add
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AddDomain $add): RedirectResponse
    {
        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:300'],
            'environment_id' => ['nullable', 'string'],
        ]);
        $domain = $add->handle($user, $project, $validated['hostname'], $validated['environment_id'] ?? null);

        return to_route('projects.domains', $project)->with('status', __('Added :domain. Publish the TXT record below, then check it.', ['domain' => $domain->displayName()]));
    }
}
