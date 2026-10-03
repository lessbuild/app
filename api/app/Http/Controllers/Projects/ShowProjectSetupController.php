<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\ProviderType;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Projects\ProjectSetupQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowProjectSetupController
{
    /**
     * Show the project's setup guide: each step from connecting a provider to measuring visits, where the project
     * stands on it, and the next thing to do. The current step's form opens inside the guide when it's short enough
     * (a provider, a website, an Analytics site), so the options it needs are loaded for that step only.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSetupQuery  $setup
     * @param  WebsitesQuery  $websites
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSetupQuery $setup, WebsitesQuery $websites): View
    {
        $guide = $setup->handle($project);
        $canChange = $user->can('update', $project);
        $inline = match (true) {
            ! $canChange || $guide->next() === null => null,
            $guide->next()->key === 'provider' && $user->can('update', $project->account) => 'provider',
            $guide->next()->key === 'website' && $project->hasService('infrastructure') && $user->can('create', [Website::class, $project]) => 'website',
            $guide->next()->key === 'analytics' && $project->hasService('analytics') && $user->can('create', [AnalyticsSite::class, $project]) => 'analytics',
            default => null,
        };
        $hosts = $inline === 'website' ? $websites->hosts($project->account_id) : collect();

        return view('projects.setup', [
            'overview' => $overview->handle($project, $user),
            'setup' => $guide,
            'canChange' => $canChange,
            'inline' => $inline === 'website' && $hosts->isEmpty() ? null : $inline,
            'providerTypes' => array_values(array_filter(ProviderType::cases(), fn (ProviderType $type): bool => $type->hostsServers())),
            'hosts' => $hosts,
            'environments' => $inline === 'website' ? $websites->environments($project->account) : collect(),
        ]);
    }
}
