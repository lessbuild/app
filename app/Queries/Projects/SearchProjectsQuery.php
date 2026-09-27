<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Project;
use App\Platform\Search\Like;
use App\Platform\Search\SearchResult;
use App\Support\Hostname;

final class SearchProjectsQuery
{
    /** @return list<SearchResult> projects whose name or slug matches */
    public function projects(Account $account, string $term, int $limit = 6): array
    {
        $pattern = Like::contains($term);

        return array_values(Project::query()
            ->where('account_id', $account->id)
            ->where(fn ($query) => $query->whereRaw("lower(name) like ? escape '\\'", [$pattern])->orWhereRaw("lower(slug) like ? escape '\\'", [$pattern]))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Project $project): SearchResult => new SearchResult($project->name, route('projects.show', $project), $project->description, __('Project')))
            ->all());
    }

    /** @return list<SearchResult> domains whose hostname matches, in the account's projects */
    public function domains(Account $account, string $term, int $limit = 6): array
    {
        $ascii = Hostname::normalize($term) ?? mb_strtolower(trim($term));

        return array_values(Domain::query()
            ->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'))
            ->whereRaw("hostname like ? escape '\\'", [Like::contains($ascii)])
            ->with('project')
            ->orderBy('hostname')
            ->limit($limit)
            ->get()
            ->map(fn (Domain $domain): SearchResult => new SearchResult($domain->displayName(), route('projects.domains', $domain->project_id), $domain->project->name, __('Domain')))
            ->all());
    }
}
