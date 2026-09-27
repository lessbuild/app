<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ScaleEnvironment
{
    /**
     * Sets how many replicas an environment runs.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes scaling.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /** Set how many replicas of each worker run, within the environment's minimum and maximum. Applies with the next deploy. */
    public function handle(User $actor, Environment $environment, int $replicas): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.scaling')) {
            throw ValidationException::withMessages(['replicas' => __('Scaling comes with the Business Deploy plan and above.')]);
        }
        if ($replicas < $environment->minimum_replicas || $replicas > $environment->maximum_replicas) {
            throw ValidationException::withMessages(['replicas' => __('Choose between :min and :max replicas.', ['min' => $environment->minimum_replicas, 'max' => $environment->maximum_replicas])]);
        }
        $environment->forceFill(['desired_replicas' => $replicas])->save();
    }
}
