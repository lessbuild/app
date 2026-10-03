<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use App\Queries\Monitoring\StatusPageReportQuery;
use App\Support\StatusBadge;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `/status/{slug}/badge.svg`: a published status page's state, or its uptime, as a badge. */
final class ShowStatusPageBadgeController
{
    /**
     * Draw the page's badge: its overall state, or with `?show=uptime` its components' average uptime over 30 days.
     *
     * @param  Request  $request
     * @param  string  $slug
     * @param  StatusPageReportQuery  $report
     * @return Response
     */
    public function __invoke(Request $request, string $slug, StatusPageReportQuery $report): Response
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $data = $report->handle($page);
        $colour = StatusBadge::COLOURS[$data['overall']] ?? StatusBadge::COLOURS['degraded'];
        $label = strtolower($data['overallLabel']);
        if ($request->query('show') === 'uptime') {
            $uptimes = array_values(array_filter(array_map(fn (array $row): ?float => $row['history']['uptime'] ?? null, $data['components']), fn (?float $uptime): bool => $uptime !== null));
            $label = $uptimes === [] ? __('no data') : __(':uptime% uptime', ['uptime' => rtrim(rtrim(number_format(array_sum($uptimes) / count($uptimes), 2), '0'), '.')]);
        }

        return response(StatusBadge::svg($request->query('label') === 'status' ? __('status') : $page->name, $label, $colour), 200, StatusBadge::headers());
    }
}
