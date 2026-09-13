<?php

namespace App\Services;

use App\Data\RepositoryChangeImpact;
use App\Data\RepositoryImpactPreview;
use App\Data\RepositoryImpactPreviewTarget;
use App\Models\Repository;
use App\Models\User;

class RepositoryImpactPreviewQuery
{
    public function __construct(
        private readonly RepositoryInventoryQuery $repositories,
        private readonly RepositoryChangeImpactEvaluator $changeImpact,
    ) {}

    /**
     * Evaluate every enabled push target in the current workspace without writing or dispatching work.
     *
     * The inventory query remains the tenant boundary; the pure evaluator remains
     * the single source of include/exclude and conservative-unknown semantics.
     *
     * @param  User  $user  Account whose current workspace owns the inventory.
     * @param  ?list<string>  $changedPaths  Normalized paths, or null when unavailable.
     * @return RepositoryImpactPreview Read-only target outcomes and safe summary counts.
     */
    public function for(User $user, ?array $changedPaths): RepositoryImpactPreview
    {
        $targets = [];
        $counts = [
            RepositoryChangeImpact::AFFECTED => 0,
            RepositoryChangeImpact::UNAFFECTED => 0,
            RepositoryChangeImpact::UNKNOWN => 0,
        ];

        $this->repositories->for($user, [
            'search' => null,
            'provider_id' => null,
            'website_id' => null,
            'status' => null,
        ])
            ->where('repositories.webhook_enabled', true)
            ->with('website:id,name,url')
            ->orderBy('repositories.name')
            ->orderBy('repositories.id')
            ->get([
                'repositories.id',
                'repositories.website_id',
                'repositories.name',
                'repositories.branch',
                'repositories.deployment_root',
                'repositories.auto_deploy_include_paths',
                'repositories.auto_deploy_exclude_paths',
                'repositories.webhook_enabled',
            ])
            ->each(function (Repository $repository) use (&$targets, &$counts, $changedPaths): void {
                $impact = $this->changeImpact->evaluate($repository, $changedPaths);
                $counts[$impact->status]++;
                $targets[] = new RepositoryImpactPreviewTarget($repository, $impact);
            });

        return new RepositoryImpactPreview($changedPaths, $targets, $counts);
    }
}
