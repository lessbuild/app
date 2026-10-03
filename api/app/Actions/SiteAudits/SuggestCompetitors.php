<?php

declare(strict_types=1);

namespace App\Actions\SiteAudits;

use App\Contracts\SiteAudits\AuditAnalyst;
use App\Contracts\SiteAudits\WebSearch;
use App\Exceptions\AccountRuleViolation;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteAudits\PublicPageFetcher;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class SuggestCompetitors
{
    /**
     * Create a new SuggestCompetitors instance.
     *
     * @param  PublicPageFetcher  $pages  Reads the site's home page.
     * @param  WebSearch  $search  Finds similar sites, when a search key is set.
     * @param  AuditAnalyst  $analyst  Picks the closest competitors.
     * @param  NormalizeSiteUrl  $urls  Checks and tidies the addresses.
     */
    public function __construct(
        private readonly PublicPageFetcher $pages,
        private readonly WebSearch $search,
        private readonly AuditAnalyst $analyst,
        private readonly NormalizeSiteUrl $urls,
    ) {}

    /**
     * Suggest a site's closest competitors from what its home page says and, when searching is set up, the web.
     * Nothing is saved: people choose which to add.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $url
     * @return list<array{name: string, url: string, reason: string}>
     *
     * @throws AccountRuleViolation
     */
    public function handle(User $actor, Project $project, string $url): array
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'audit']);
        $url = $this->urls->handle($url);
        try {
            $site = $this->pages->fetch($url);
            $query = trim(($site['title'] !== '' ? $site['title'] : (string) parse_url($url, PHP_URL_HOST)).' alternatives competitors');
            $suggestions = $this->analyst->suggestCompetitors($site, $this->search->search($query, 10));
        } catch (RuntimeException $exception) {
            throw new AccountRuleViolation('url', $exception->getMessage());
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $found = [];
        foreach ($suggestions as $suggestion) {
            try {
                $suggestionUrl = $this->urls->handle($suggestion['url']);
            } catch (AccountRuleViolation) {
                continue;
            }
            $suggestionHost = (string) parse_url($suggestionUrl, PHP_URL_HOST);
            if ($suggestionHost !== $host && ! isset($found[$suggestionHost])) {
                $found[$suggestionHost] = ['name' => $suggestion['name'], 'url' => $suggestionUrl, 'reason' => $suggestion['reason']];
            }
        }

        return array_values($found);
    }
}
