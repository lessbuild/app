<?php

declare(strict_types=1);

namespace App\Support\StatusPages;

use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Queries\Monitoring\StatusPageReportQuery;

/**
 * Turns a status page and StatusPageReportQuery's report into the JSON the public page shows. Nothing private leaves:
 * components show their label and type, never what they check.
 *
 * @phpstan-import-type Report from StatusPageReportQuery
 */
final class StatusPagePayload
{
    /**
     * Describe the page, its branding and its report.
     *
     * @param  StatusPage  $page
     * @param  Report  $report
     * @param  array{name: string, logo: string|null, color: string|null}|null  $branding  The account's white-label branding, if it has one.
     * @return array<string, mixed>
     */
    public static function from(StatusPage $page, array $report, ?array $branding): array
    {
        return [
            'page' => [
                'slug' => $page->slug,
                'name' => $page->name,
                'description' => $page->description,
                'owner' => $branding['name'] ?? $page->account->name,
                'url' => $page->publicUrl(),
                'reportUrl' => route('status.report', $page->slug),
            ],
            'branding' => $branding,
            'overall' => $report['overall'],
            'overallLabel' => $report['overallLabel'],
            'groups' => $report['groups'],
            'components' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'group' => $row['group'],
                'type' => $row['type'],
                'state' => $row['state'],
                'stateLabel' => $row['stateLabel'],
                'checkedAt' => $row['checkedAt']?->toIso8601String(),
                'incidents' => array_map(fn (Incident $incident): array => ['title' => $incident->title, 'openedAt' => $incident->opened_at->toIso8601String()], $row['incidents']),
                'history' => $row['history'],
            ], $report['components']),
            'activeUpdates' => array_map(self::update(...), $report['activeUpdates']),
            'upcomingMaintenance' => array_map(self::update(...), $report['upcomingMaintenance']),
            'pastUpdates' => array_map(self::update(...), $report['pastUpdates']),
            'recentIncidents' => array_map(fn (Incident $incident): array => [
                'title' => $incident->title,
                'openedAt' => $incident->opened_at->toIso8601String(),
                'resolvedAt' => $incident->resolved_at?->toIso8601String(),
            ], $report['recentIncidents']),
            'checkedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Describe a status update as the public page shows it.
     *
     * @param  StatusUpdate  $update
     * @return array<string, mixed>
     */
    private static function update(StatusUpdate $update): array
    {
        return [
            'id' => $update->id,
            'kind' => $update->kind,
            'status' => $update->status,
            'statusLabel' => $update->statusLabel(),
            'severity' => $update->severity,
            'title' => $update->title,
            'message' => $update->message,
            'rootCause' => $update->root_cause,
            'remediation' => $update->remediation,
            'followUp' => $update->follow_up,
            'startsAt' => $update->starts_at->toIso8601String(),
            'endsAt' => $update->ends_at?->toIso8601String(),
            'updatedAt' => $update->updated_at?->toIso8601String(),
        ];
    }
}
