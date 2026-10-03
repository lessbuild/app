<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use App\Services\Deploy\EnvironmentRecipes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RunEnvironmentRecipesNow
{
    /**
     * Create a new RunEnvironmentRecipesNow instance.
     *
     * Runs environment recipes by hand.
     *
     * @param  EnvironmentRecipes  $recipes  Builds and queues the command.
     */
    public function __construct(private readonly EnvironmentRecipes $recipes) {}

    /**
     * Run the environment's recipes on each of its servers, as a command in each server's history. The person must be
     * allowed to run commands on every one; servers busy with another command are skipped.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @return array{queued: int, busy: int}
     */
    public function handle(User $actor, Environment $environment): array
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if ($environment->recipes()->doesntExist()) {
            throw ValidationException::withMessages(['recipes' => __('Add a recipe first.')]);
        }
        $servers = $this->recipes->servers($environment);
        if ($servers === []) {
            throw ValidationException::withMessages(['recipes' => __('None of this environment’s websites is on an active server yet.')]);
        }
        foreach ($servers as $server) {
            Gate::forUser($actor)->authorize('runCommands', $server);
        }
        $queued = 0;
        foreach ($servers as $server) {
            $queued += (int) ($this->recipes->queueOn($environment, $server, $actor) !== null);
        }

        return ['queued' => $queued, 'busy' => count($servers) - $queued];
    }
}
