<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\StatusPageForm;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Models\User;
use App\Queries\Monitoring\StatusPageReportQuery;
use App\Queries\Monitoring\StatusPagesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowStatusPageController
{
    /**
     * Show a status page as its team sees it: its components' health, badges and embed code, its custom domain, and
     * the incidents and maintenance posted to it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  ProjectOverviewQuery  $overview
     * @param  StatusPageReportQuery  $report
     * @param  StatusPagesQuery  $pages
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, StatusPage $page, ProjectOverviewQuery $overview, StatusPageReportQuery $report, StatusPagesQuery $pages): JsonResponse
    {
        $canManage = $user->can('update', $page);
        $state = $report->handle($page);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'page' => [
                'id' => $page->id,
                'name' => $page->name,
                'slug' => $page->slug,
                'published' => (bool) $page->published,
                'url' => $page->publicUrl(),
                'badgeUrl' => route('status.badge', $page->slug),
                'uptimeBadgeUrl' => route('status.badge', [$page->slug, 'show' => 'uptime']),
                'embedUrl' => route('status.embed', $page->slug),
                'customDomain' => $page->custom_domain,
                'domainVerified' => $page->custom_domain_verified_at !== null,
                'domainRecord' => $page->custom_domain === null ? null : ['name' => $page->domainRecordName(), 'value' => $page->domainRecordValue()],
                'domainTarget' => (string) config('monitoring.status_pages.domain_target'),
            ],
            'overall' => $state['overall'],
            'overallLabel' => $state['overallLabel'],
            'components' => array_map(fn (array $row): array => [
                'name' => $row['name'], 'type' => $row['type'], 'uptime' => $row['history']['uptime'] ?? null, 'state' => $row['state'], 'stateLabel' => $row['stateLabel'],
            ], $state['components']),
            'subscribers' => $page->subscriptions()->whereNotNull('verified_at')->count(),
            'updates' => $page->updates()->orderByDesc('starts_at')->orderByDesc('id')->limit(50)->get()->map(fn (StatusUpdate $update): array => [
                'id' => $update->id,
                'title' => $update->title,
                'kind' => $update->kind,
                'status' => $update->status,
                'statusLabel' => $update->statusLabel(),
                'closed' => $update->isClosed(),
                'severity' => $update->severity,
                'message' => $update->message,
                'startsAt' => $update->starts_at->toIso8601String(),
                'endsAt' => $update->ends_at?->toIso8601String(),
                'rootCause' => $update->root_cause,
                'remediation' => $update->remediation,
                'followUp' => $update->follow_up,
            ])->values(),
            'statuses' => array_map(fn (array $statuses): array => array_map(fn (string $status): array => ['value' => $status, 'label' => __(str_replace('_', ' ', ucfirst($status)))], $statuses), StatusUpdate::STATUSES),
            'form' => $canManage ? StatusPageForm::for($pages->monitors($project->account), $page) : null,
            'canManage' => $canManage,
        ]);
    }
}
