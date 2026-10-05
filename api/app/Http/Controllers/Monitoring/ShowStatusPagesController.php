<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\StatusPageForm;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Models\User;
use App\Queries\Monitoring\MonitorActivityQuery;
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
     * @param  MonitorActivityQuery  $activities
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, StatusPagesQuery $pages, MonitorActivityQuery $activities): JsonResponse
    {
        $canManage = $user->can('create', [StatusPage::class, $project]);

        $list = $pages->handle($project->account_id)->load('components.monitor');
        $activity = $activities->handle(array_values($list->flatMap(fn (StatusPage $page) => $page->components->pluck('monitor'))->filter()->unique('id')->all()));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'pages' => $list->map(function (StatusPage $page) use ($activity): array {
                $components = $page->components->sortBy('position')->filter(fn (StatusPageComponent $component): bool => $component->monitor !== null);

                return [
                    'id' => $page->id,
                    'name' => $page->name,
                    'slug' => $page->slug,
                    'domain' => $page->custom_domain_verified_at !== null ? $page->custom_domain : null,
                    'components' => (int) ($page->components_count ?? 0),
                    'subscribers' => (int) ($page->subscriptions_count ?? 0),
                    'published' => (bool) $page->published,
                    // What the page shows now: its first components with their health and 30-day uptime.
                    'preview' => $components->take(3)->map(fn (StatusPageComponent $component): array => [
                        'name' => $component->label,
                        'health' => $component->monitor?->healthLabel() ?? 'Unknown',
                        'uptime' => $activity[$component->monitor_id]['uptime'] ?? null,
                    ])->values(),
                    'allUp' => $components->every(fn (StatusPageComponent $component): bool => in_array($component->monitor?->healthLabel(), ['Up', 'Paused'], true)),
                ];
            })->values(),
            'form' => $canManage ? StatusPageForm::for($pages->monitors($project->account), null) : null,
            'canManage' => $canManage,
        ]);
    }
}
