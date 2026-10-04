<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use App\Queries\Monitoring\StatusPageReportQuery;
use App\Services\Accounts\AccountBranding;
use App\Support\StatusPages\StatusPagePayload;
use Illuminate\Http\JsonResponse;

final class ShowPublicStatusPageController
{
    /**
     * Show a published status page to anyone: its overall state, components and their 30-day history, current and
     * planned updates and the last 30 days, with the account's white-label branding. Drafts are a 404.
     *
     * @param  string  $slug
     * @param  StatusPageReportQuery  $report
     * @param  AccountBranding  $branding
     * @return JsonResponse
     */
    public function __invoke(string $slug, StatusPageReportQuery $report, AccountBranding $branding): JsonResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->with('account')->firstOrFail();

        return response()->json(StatusPagePayload::from($page, $report->handle($page), $branding->for($page->account)));
    }
}
