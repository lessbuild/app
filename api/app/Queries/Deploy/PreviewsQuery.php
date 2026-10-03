<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Models\EnvironmentVariable;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Deploy\PreviewConfiguration;
use Illuminate\Database\Eloquent\Collection;

/** The previews page: the project's previews, the plan's allowance, and which repositories have previews on. */
final class PreviewsQuery
{
    /**
     * Create a new PreviewsQuery instance.
     *
     * Reads the previews page.
     *
     * @param  Entitlements  $entitlements  Reads the plan's preview allowance.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Get the project's previews (open ones first, then the 20 most recently closed), each with the secrets its source
     * environment could share when the viewer may approve them; how many previews the account has open against its
     * limit; and the repositories with previews on.
     *
     * @param  Project  $project
     * @param  User  $viewer
     * @return array{open: Collection<int, Preview>, closed: Collection<int, Preview>, approvable: array<int, list<string>>, used: int, limit: int|null, allowed: bool, repositories: Collection<int, Repository>}
     */
    public function handle(Project $project, User $viewer): array
    {
        $relations = ['sourceRepository', 'website', 'repository', 'sourceEnvironment', 'secretApprovals' => fn ($query) => $query->whereNull('revoked_at')->with('approver')];
        $open = Preview::query()->where('project_id', $project->id)->where('status', '!=', Preview::STATUS_CLOSED)->with($relations)->latest('last_activity_at')->get();
        $closed = Preview::query()->where('project_id', $project->id)->where('status', Preview::STATUS_CLOSED)->with($relations)->latest('closed_at')->limit(20)->get();
        $approvable = [];
        foreach ($open as $preview) {
            if ($preview->source_environment_id !== null && $viewer->can('approveSecrets', $preview)) {
                $variables = EnvironmentVariable::query()->where('environment_id', $preview->source_environment_id)->orderBy('key')->get()->all();
                $approvable[$preview->id] = array_values(array_map(fn (EnvironmentVariable $variable): string => $variable->key, array_filter($variables, PreviewConfiguration::isApprovable(...))));
            }
        }
        $entitlements = $this->entitlements->for($project->account);

        return [
            'open' => $open,
            'closed' => $closed,
            'approvable' => $approvable,
            'used' => Preview::query()->whereHas('project', fn ($query) => $query->where('account_id', $project->account_id))->where('status', '!=', Preview::STATUS_CLOSED)->count(),
            'limit' => $entitlements->limit('deploy.previews.max'),
            'allowed' => $entitlements->has('deploy.previews'),
            'repositories' => Repository::query()->where('project_id', $project->id)->where('previews_enabled', true)->orderBy('name')->get(),
        ];
    }
}
