<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use App\Platform\Search\Like;
use App\Platform\Search\SearchResult;
use App\Support\Hostname;

final class SearchProjectsQuery
{
    /**
     * Create a new SearchProjectsQuery instance.
     *
     * @param  VisibleProjects  $visible  Limits results to the projects the person can see.
     */
    public function __construct(private readonly VisibleProjects $visible) {}

    /**
     * Find projects whose name or slug contains the term, for the command palette.
     *
     * @param  Account  $account
     * @param  string  $term
     * @param  int  $limit
     * @param  User|null  $user  limits the results to the projects they can see
     * @return list<SearchResult> projects whose name or slug matches
     */
    public function projects(Account $account, string $term, int $limit = 6, ?User $user = null): array
    {
        $pattern = Like::contains($term);

        return array_values($this->visible->scope(Project::query()->where('account_id', $account->id), $account->id, $user)
            ->where(fn ($query) => $query->whereRaw("lower(name) like ? escape '\\'", [$pattern])->orWhereRaw("lower(slug) like ? escape '\\'", [$pattern]))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Project $project): SearchResult => new SearchResult($project->name, route('projects.show', $project), $project->description, __('Project')))
            ->all());
    }

    /**
     * Find domains whose hostname contains the term. Internationalised terms are converted to their ASCII form first,
     * which is how hostnames are stored.
     *
     * @param  Account  $account
     * @param  string  $term
     * @param  int  $limit
     * @param  User|null  $user  limits the results to the projects they can see
     * @return list<SearchResult> domains whose hostname matches, in the account's projects
     */
    public function domains(Account $account, string $term, int $limit = 6, ?User $user = null): array
    {
        $ascii = Hostname::normalize($term) ?? mb_strtolower(trim($term));

        return array_values(Domain::query()
            ->whereIn('project_id', $this->visible->scope(Project::query()->where('account_id', $account->id), $account->id, $user)->select('id'))
            ->whereRaw("hostname like ? escape '\\'", [Like::contains($ascii)])
            ->with('project')
            ->orderBy('hostname')
            ->limit($limit)
            ->get()
            ->map(fn (Domain $domain): SearchResult => new SearchResult($domain->displayName(), route('projects.domains', $domain->project_id), $domain->project->name, __('Domain')))
            ->all());
    }
}
