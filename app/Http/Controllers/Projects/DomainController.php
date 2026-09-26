<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\AddDomain;
use App\Domain\Projects\Actions\RemoveDomain;
use App\Domain\Projects\Actions\VerifyDomain;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Queries\ProjectDomainsQuery;
use App\Domain\Projects\Queries\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DomainController
{
    public function index(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectDomainsQuery $domains): View
    {
        return view('projects.domains', [
            'overview' => $overview->handle($project, $user),
            'domains' => $domains->handle($project),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, Project $project, AddDomain $add): RedirectResponse
    {
        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:300'],
            'environment_id' => ['nullable', 'string'],
        ]);
        $domain = $add->handle($user, $project, $validated['hostname'], $validated['environment_id'] ?? null);

        return to_route('projects.domains', $project)->with('status', __('Added :domain. Publish the TXT record below, then check it.', ['domain' => $domain->displayName()]));
    }

    public function verify(#[CurrentUser] User $user, Project $project, string $domain, VerifyDomain $verify): RedirectResponse
    {
        $target = $project->domains()->findOrFail($domain);

        return $verify->handle($user, $target)
            ? to_route('projects.domains', $project)->with('status', __(':domain is verified.', ['domain' => $target->displayName()]))
            : to_route('projects.domains', $project)->with('notice', __('We couldn’t find the TXT record for :domain yet. DNS changes can take a while; check again later.', ['domain' => $target->displayName()]));
    }

    public function destroy(#[CurrentUser] User $user, Project $project, string $domain, RemoveDomain $remove): RedirectResponse
    {
        $target = $project->domains()->findOrFail($domain);
        $remove->handle($user, $target);

        return to_route('projects.domains', $project)->with('status', __(':domain removed.', ['domain' => $target->displayName()]));
    }
}
