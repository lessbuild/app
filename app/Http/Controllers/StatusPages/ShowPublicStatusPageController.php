<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use App\Queries\Monitoring\StatusPageReportQuery;
use Illuminate\Http\Response;

/** The public status page at `/status/{slug}` (the address both old apps used). */
final class ShowPublicStatusPageController
{
    /**
     * The published status page, never cached by shared caches since it carries a CSRF token and flash messages.
     */
    public function __invoke(string $slug, StatusPageReportQuery $report): Response
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->with('account')->firstOrFail();

        // The subscribe form carries a CSRF token and flash messages, so shared caches mustn't keep the page.
        return response()->view('status-pages.show', ['page' => $page, ...$report->handle($page)])
            ->header('Cache-Control', 'no-store, private');
    }
}
