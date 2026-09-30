<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ListDailyRowsController
{
    /**
     * The breakdowns a row can be for; all is the whole site.
     *
     * @var list<string>
     */
    public const DIMENSIONS = ['all', 'path', 'source', 'channel', 'campaign', 'country', 'city', 'device', 'browser', 'operating_system', 'screen_size'];

    /**
     * How many rows one page holds.
     *
     * @var int
     */
    public const PER_PAGE = 5000;

    /**
     * Return a site's daily totals as flat rows (`GET /api/v1/analytics/sites/{site}/rows`), one per day and value of
     * a breakdown, for spreadsheets and dashboards such as Looker Studio: pageviews, visits, visitors, conversions,
     * converted visits and bounces. Up to 400 days at a time, a page of rows at a time.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AnalyticsSite $site): JsonResponse
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account && $site->project->account_id === $account->id && $user->can('view', $site), 404);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'dimension' => ['nullable', Rule::in(self::DIMENSIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 400, 422, 'Ask for up to 400 days at a time.');
        $dimension = $data['dimension'] ?? 'all';
        $page = (int) ($data['page'] ?? 1);

        $rows = AnalyticsDailyAggregate::query()->where('site_id', $site->id)->where('dimension', $dimension)
            ->whereBetween('local_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->orderBy('local_date')->orderBy('dimension_value')->orderBy('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PER_PAGE)->map(fn (AnalyticsDailyAggregate $row): array => [
                'date' => $row->local_date->toDateString(),
                'value' => $dimension === 'all' ? null : $row->dimension_value,
                'pageviews' => (int) $row->pageviews,
                'visits' => (int) $row->visits,
                'visitors' => (int) $row->visitors,
                'conversions' => (int) $row->conversions,
                'converted_visits' => (int) $row->converted_visits,
                'bounces' => (int) $row->bounces,
                'bounce_eligible' => (int) $row->bounce_eligible,
            ])->values()->all(),
            'meta' => ['site_id' => $site->id, 'dimension' => $dimension, 'timezone' => $site->timezone, 'page' => $page, 'next_page' => $rows->count() > self::PER_PAGE ? $page + 1 : null],
        ]);
    }
}
