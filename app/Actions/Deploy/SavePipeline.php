<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\AccountRuleViolation;
use App\Models\DeployPipeline;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SavePipeline
{
    /**
     * Create a deploy pipeline: two to eight of the project's repositories, in the order they deploy. The person must
     * be able to deploy each of them.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $name
     * @param  list<int>  $repositoryIds
     * @return DeployPipeline
     */
    public function handle(User $actor, Project $project, string $name, array $repositoryIds): DeployPipeline
    {
        $repositoryIds = array_values(array_unique(array_map('intval', $repositoryIds)));
        if (count($repositoryIds) < 2 || count($repositoryIds) > 8) {
            throw new AccountRuleViolation('repository_ids', __('Choose two to eight different repositories.'));
        }
        $repositories = Repository::query()->where('project_id', $project->id)->whereKey($repositoryIds)->get()->keyBy('id');
        if ($repositories->count() !== count($repositoryIds)) {
            throw new AccountRuleViolation('repository_ids', __('Choose repositories from this project.'));
        }
        foreach ($repositories as $repository) {
            Gate::forUser($actor)->authorize('deploy', $repository);
        }
        $pipeline = new DeployPipeline;
        $pipeline->forceFill(['project_id' => $project->id, 'created_by' => $actor->id, 'name' => mb_substr(trim($name), 0, 80), 'repository_ids' => $repositoryIds])->save();

        return $pipeline;
    }
}
