<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\WebsiteFormOptions;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowWebsitesController
{
    /**
     * List the account's websites, with the plan's limit and (for people who may add one) the form's choices.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  WebsitesQuery  $websites
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, WebsitesQuery $websites, Entitlements $entitlements): JsonResponse
    {
        $canManage = $user->can('create', [Website::class, $project]);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'websites' => $websites->handle($project->account_id)->map(fn (Website $website): array => [
                'id' => $website->id,
                'name' => $website->name,
                'url' => $website->url,
                'server' => $website->server?->label(),
                'status' => $website->provisioning_status,
                'directory' => $website->deployment_slug,
                'php' => $website->phpVersion(),
                'healthChecked' => $website->health_check_enabled,
                'environment' => $website->environment !== null ? $website->environment->project->name.' · '.$website->environment->name : null,
                'cdn' => (bool) $website->getAttribute('cdn'),
            ])->values(),
            'limit' => $entitlements->for($project->account)->limit('deploy.websites.max'),
            'options' => $canManage ? WebsiteFormOptions::for($websites, $project->account) : null,
            'canManage' => $canManage,
        ]);
    }
}
