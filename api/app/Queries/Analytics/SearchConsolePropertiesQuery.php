<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsSite;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class SearchConsolePropertiesQuery
{
    /**
     * Create a new SearchConsolePropertiesQuery instance.
     *
     * @param  SearchConsole  $searchConsole  Reads Google Search Console.
     */
    public function __construct(private readonly SearchConsole $searchConsole) {}

    /**
     * List the Search Console properties a connected site can choose from (cached for ten minutes), with an error
     * instead when Google refuses; empty when the site isn't connected.
     *
     * @param  AnalyticsSite  $site
     * @return array{properties: list<string>, error: string|null}
     */
    public function handle(AnalyticsSite $site): array
    {
        $token = $site->search_console_token;
        if ($token === null) {
            return ['properties' => [], 'error' => null];
        }
        try {
            return ['properties' => Cache::remember('search-console.properties.'.$site->id.'.'.hash('sha256', $token), 600, fn (): array => $this->searchConsole->properties($token)), 'error' => null];
        } catch (RuntimeException $exception) {
            return ['properties' => [], 'error' => $exception->getMessage()];
        }
    }
}
