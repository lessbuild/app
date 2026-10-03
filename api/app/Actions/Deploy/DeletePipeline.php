<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\DeployPipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeletePipeline
{
    /**
     * Delete a pipeline and its run history (deploys it started are kept).
     *
     * @param  User  $actor
     * @param  DeployPipeline  $pipeline
     * @return void
     */
    public function handle(User $actor, DeployPipeline $pipeline): void
    {
        Gate::forUser($actor)->authorize('update', Project::query()->findOrFail($pipeline->project_id));
        $pipeline->delete();
    }
}
