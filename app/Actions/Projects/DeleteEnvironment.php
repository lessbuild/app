<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\EnvironmentKind;
use App\Events\Projects\EnvironmentDeleted;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteEnvironment
{
    /**
     * Deletes an environment. The production environment can't be deleted.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @return void
     */
    public function handle(User $actor, Environment $environment): void
    {
        Gate::forUser($actor)->authorize('update', $environment->project);
        if ($environment->kind === EnvironmentKind::Production) {
            throw ProjectRuleViolation::productionIsFixed();
        }

        $environment->delete();
        EnvironmentDeleted::dispatch($environment->project, $environment->name, $actor);
    }
}
