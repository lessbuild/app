<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use App\Queries\Monitoring\StatusPageReportQuery;
use Illuminate\Http\Response;

/** `/status/{slug}/embed`: a small widget for a customer's own site, shown in an iframe. */
final class ShowStatusPageEmbedController
{
    /**
     * Show the published page's overall state as a one-line widget that links to the full page and refreshes itself
     * every minute.
     *
     * @param  string  $slug
     * @param  StatusPageReportQuery  $report
     * @return Response
     */
    public function __invoke(string $slug, StatusPageReportQuery $report): Response
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();

        return response()->view('status-pages.embed', ['page' => $page, ...$report->handle($page)])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
