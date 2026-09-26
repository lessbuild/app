<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\EnvironmentKind;
use App\Domain\Projects\Events\EnvironmentDeleted;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\Environment;
use Illuminate\Support\Facades\Gate;

final class DeleteEnvironment
{
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
