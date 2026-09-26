<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\EnvironmentKind;
use App\Domain\Projects\Events\EnvironmentCreated;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\Environment;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreateEnvironment
{
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
