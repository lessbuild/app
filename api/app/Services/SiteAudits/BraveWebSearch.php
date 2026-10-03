<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use App\Contracts\SiteAudits\WebSearch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Searches with the Brave Search API, when `BRAVE_SEARCH_API_KEY` is set. */
final class BraveWebSearch implements WebSearch
{
    /**
     * Determine whether a Brave Search key is configured.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('site_audits.brave_search_key'));
    }

    /**
     * Search the web, returning nothing when searching isn't set up or fails.
     *
     * @param  string  $query
     * @param  int  $count
     * @return list<array{title: string, url: string, description: string}>
     */
    public function search(string $query, int $count = 10): array
    {
        if (! $this->configured()) {
            return [];
        }
        try {
            $response = Http::withHeaders(['X-Subscription-Token' => (string) config('site_audits.brave_search_key')])
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->get('https://api.search.brave.com/res/v1/web/search', ['q' => mb_substr($query, 0, 380), 'count' => max(1, min(20, $count))]);
        } catch (ConnectionException) {
            return [];
        }
        $results = $response->successful() ? $response->json('web.results') : null;
        if (! is_array($results)) {
            return [];
        }
        $found = [];
        foreach ($results as $result) {
            if (is_array($result) && is_string($result['url'] ?? null)) {
                $found[] = [
                    'title' => mb_substr(strip_tags((string) ($result['title'] ?? '')), 0, 200),
                    'url' => $result['url'],
                    'description' => mb_substr(strip_tags((string) ($result['description'] ?? '')), 0, 400),
                ];
            }
        }

        return $found;
    }
}
