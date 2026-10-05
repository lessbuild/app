<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveIncidentPostmortem;
use App\Data\Monitoring\MonitorObservation;
use App\Models\AlertDelivery;
use App\Models\Build;
use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\IncidentSummary;
use App\Support\Monitoring\ObservationText;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowIncidentController
{
    /**
     * Show an incident: what failed, who's on it, its timeline, and its post-mortem (drafted from the timeline, the
     * alert and the deploys before it when none is written yet) and status page report.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Incident  $incident
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectIncidentsQuery  $incidents
     * @param  IncidentSummary  $summary
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Incident $incident, ProjectOverviewQuery $overview, ProjectIncidentsQuery $incidents, IncidentSummary $summary): JsonResponse
    {
        $reason = $incident->latest_observation['reason'] ?? null;
        $details = $incident->latest_observation['details'] ?? null;
        $report = $incident->postmortem_status_update_id === null ? null : StatusUpdate::query()->with('statusPage')->find($incident->postmortem_status_update_id);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'incident' => [
                'id' => $incident->id,
                'title' => $incident->title,
                'status' => $incident->status,
                'statusLabel' => __($incident->statusLabel()),
                'version' => $incident->state_version,
                'observation' => MonitorObservation::label(is_string($reason) ? $reason : null),
                'details' => is_array($details) ? ObservationText::details($details) : [],
                'configuration' => ObservationText::configuration($incident->rule_snapshot ?? []),
                'openedAt' => $incident->opened_at->toIso8601String(),
                'lastBreachedAt' => $incident->last_breached_at->toIso8601String(),
                'resolvedAt' => $incident->resolved_at?->toIso8601String(),
                'acknowledgedAt' => $incident->acknowledged_at?->toIso8601String(),
                'acknowledgedBy' => $incident->acknowledgedBy?->name,
                'assigneeId' => $incident->assignee_id,
                'assignee' => $incident->assignee?->name,
                'monitorId' => $incident->monitor_id,
                'postmortem' => $incident->postmortem,
            ],
            'sections' => array_map(fn (string $heading): string => __($heading), SaveIncidentPostmortem::SECTIONS),
            'draft' => $incident->postmortem === null ? $summary->draft($incident) : [],
            'activities' => $incident->activities()->with('actor')->latest('id')->limit(100)->get()->map(fn (IncidentActivity $activity): array => [
                'id' => $activity->id, 'action' => $activity->action, 'label' => __($activity->label()), 'actor' => $activity->actor?->name, 'note' => $activity->note, 'at' => $activity->created_at?->toIso8601String(),
            ])->values(),
            'assignees' => array_map(fn (User $member): array => ['value' => (string) $member->id, 'label' => $member->name], $incidents->assignees($project)),
            'statusPages' => StatusPage::query()->where('account_id', $incident->account_id)->orderBy('name')->get(['id', 'name', 'published'])
                ->map(fn (StatusPage $page): array => ['value' => (string) $page->id, 'label' => $page->published ? $page->name : $page->name.' ('.__('draft').')'])->values(),
            'severities' => array_map(fn (string $severity): array => ['value' => $severity, 'label' => __(ucfirst($severity))], StatusUpdate::SEVERITIES),
            'report' => $report === null ? null : [
                'statusPageId' => $report->status_page_id, 'page' => $report->statusPage->name, 'url' => $report->statusPage->published ? $report->statusPage->publicUrl() : null,
                'severity' => $report->severity, 'title' => $report->title,
            ],
            // Where alerts about it went, once per destination, with how the latest one went.
            'told' => AlertDelivery::query()->with('destination')->where('incident_id', $incident->id)->latest('created_at')->get()
                ->unique('alert_destination_id')->map(fn (AlertDelivery $delivery): array => [
                    'name' => $delivery->destination->name, 'type' => $delivery->destination->type->label(), 'status' => $delivery->status->value,
                ])->values(),
            'likelyCause' => $this->likelyCause($incident),
            'canRespond' => $user->can('update', $incident),
        ]);
    }

    /**
     * Find a deploy that went live in the incident's environment in the hour before it opened, the newest first: often
     * the change that caused it.
     *
     * @param  Incident  $incident
     * @return array{id: int, commitMessage: string|null, repository: string, minutes: int}|null
     */
    private function likelyCause(Incident $incident): ?array
    {
        $environmentId = $incident->monitor()->withTrashed()->value('environment_id') ?? $incident->alertRule()->value('environment_id');
        if (! is_string($environmentId)) {
            return null;
        }
        $build = Build::query()->with('repository')->where('environment_id', $environmentId)->where('status', Build::STATUS_SUCCEEDED)
            ->whereBetween('finished_at', [$incident->opened_at->subHour(), $incident->opened_at])->latest('finished_at')->first();

        return $build === null || $build->finished_at === null ? null : [
            'id' => $build->id, 'commitMessage' => $build->commit_message, 'repository' => $build->repository->name,
            'minutes' => (int) round($build->finished_at->diffInSeconds($incident->opened_at, true) / 60),
        ];
    }
}
