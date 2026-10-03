<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\EnvironmentKind;
use App\Events\Projects\EnvironmentCreated;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreateEnvironment
{
    /**
     * Add a non-production environment to the project. Its slug comes from the name and must be unique in the project.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $name
     * @param  EnvironmentKind  $kind
     * @return Environment
     */
    public function handle(User $actor, Project $project, string $name, EnvironmentKind $kind): Environment
    {
        Gate::forUser($actor)->authorize('update', $project);
        if ($kind === EnvironmentKind::Production) {
            throw ProjectRuleViolation::productionIsFixed();
        }
        $slug = Str::slug($name) ?: Str::lower(Str::random(6));
        if ($project->environments()->where('slug', $slug)->exists()) {
            throw ProjectRuleViolation::environmentNameTaken();
        }

        $environment = new Environment;
        $environment->project()->associate($project);
        $environment->forceFill(['name' => trim($name), 'slug' => $slug, 'kind' => $kind])->save();

        EnvironmentCreated::dispatch($environment, $actor);

        return $environment;
    }
}
