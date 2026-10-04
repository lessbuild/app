<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\StatusPageForm;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowStatusPagesController
{
    /**
     * List the account's status pages, with the monitors a new one can show.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  StatusPagesQuery  $pages
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, StatusPagesQuery $pages): JsonResponse
    {
        $canManage = $user->can('create', [StatusPage::class, $project]);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'pages' => $pages->handle($project->account_id)->map(fn (StatusPage $page): array => [
                'id' => $page->id,
                'name' => $page->name,
                'slug' => $page->slug,
                'components' => (int) ($page->components_count ?? 0),
                'subscribers' => (int) ($page->subscriptions_count ?? 0),
                'published' => (bool) $page->published,
            ])->values(),
            'form' => $canManage ? StatusPageForm::for($pages->monitors($project->account), null) : null,
            'canManage' => $canManage,
        ]);
    }
}
