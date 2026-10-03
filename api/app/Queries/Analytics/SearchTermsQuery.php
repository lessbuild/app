<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsSite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class SearchTermsQuery
{
    /**
     * Create a new SearchTermsQuery instance.
     *
     * @param  SearchConsole  $searchConsole  Reads Google Search Console.
     */
    public function __construct(private readonly SearchConsole $searchConsole) {}

    /**
     * Get what people searched on Google before visiting the site over a period (top ten queries, from Search
     * Console, cached for six hours), or null when the site isn't connected. Errors are returned, not thrown, so the
     * rest of the report still shows.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  string|null  $path  only pages whose URL contains this
     * @return array{property: string, rows: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, error: string|null}|null
     */
    public function handle(AnalyticsSite $site, CarbonImmutable $start, CarbonImmutable $end, ?string $path = null): ?array
    {
        if ($site->search_console_token === null || $site->search_console_property === null) {
            return null;
        }
        $token = $site->search_console_token;
        $property = $site->search_console_property;
        $key = 'search-terms.'.$site->id.'.'.hash('sha256', $property.'|'.$start->toDateString().'|'.$end->toDateString().'|'.$path);
        try {
            $rows = Cache::remember($key, 6 * 3600, fn (): array => $this->searchConsole->topQueries($token, $property, $start, $end, $path));
        } catch (RuntimeException $exception) {
            return ['property' => $property, 'rows' => [], 'error' => $exception->getMessage()];
        }

        return ['property' => $property, 'rows' => $rows, 'error' => null];
    }
}
