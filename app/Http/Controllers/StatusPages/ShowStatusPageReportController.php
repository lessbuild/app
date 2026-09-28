<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Queries\Monitoring\StatusPageReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/** `/status/{slug}/report.json`: Deployer's public JSON snapshot, same shape, plus the detailed state. */
final class ShowStatusPageReportController
{
    /**
     * Return the published page's report as JSON: overall state, components and the latest updates.
     *
     * @param  string  $slug
     * @param  StatusPageReportQuery  $query
     * @return JsonResponse
     */
    public function __invoke(string $slug, StatusPageReportQuery $query): JsonResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $report = $query->handle($page);
        $updates = $page->updates()->orderByDesc('starts_at')->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'name' => $page->name,
            'status' => $report['overall'] === 'operational' ? 'operational' : 'degraded',
            'state' => $report['overall'],
            'updated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'components' => array_map(fn (array $component): array => [
                'name' => $component['name'],
                'operational' => $component['state'] === 'operational',
                'status' => $component['state'] === 'operational' ? 'Operational' : 'Degraded',
                'state' => $component['state'],
                'uptime_30d' => $component['history']['uptime'] ?? null,
                'checked_at' => $component['checkedAt']?->toIso8601String(),
                'open_incidents' => array_map(fn (Incident $incident): array => ['title' => $incident->title, 'opened_at' => $incident->opened_at->toIso8601String()], $component['incidents']),
            ], $report['components']),
            'incidents' => $updates->map(fn (StatusUpdate $update): array => [
                'kind' => $update->kind,
                'status' => $update->status,
                'severity' => $update->severity,
                'title' => $update->title,
                'message' => $update->message,
                'starts_at' => $update->starts_at->toIso8601String(),
                'ends_at' => $update->ends_at?->toIso8601String(),
                'resolved_at' => $update->resolved_at?->toIso8601String(),
            ])->values()->all(),
        ])->header('Cache-Control', 'public, max-age=30');
    }
}
