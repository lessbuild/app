<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\Environment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetEnvironmentRuntime
{
    /**
     * Create a new SetEnvironmentRuntime instance.
     *
     * Hibernates and wakes environments by hand.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes hibernation.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Queue the environment to hibernate (needs `deploy.hibernation`) or to run with its desired replicas.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  'running'|'hibernated'  $state
     * @return void
     */
    public function handle(User $actor, Environment $environment, string $state): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if ($state === 'hibernated' && ! $this->entitlements->for($environment->project->account)->has('deploy.hibernation')) {
            throw ValidationException::withMessages(['state' => __('Hibernation comes with the Starter Deploy plan and above.')]);
        }
        ApplyEnvironmentRuntime::dispatch($environment->id, $state === 'hibernated');
    }
}
