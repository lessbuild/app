<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateEnvironmentHibernation
{
    /**
     * The idle times an environment can hibernate after, in minutes.
     *
     * @var list<int>
     */
    public const MINUTES = [5, 15, 30, 60, 120, 1440];

    /**
     * Create a new UpdateEnvironmentHibernation instance.
     *
     * Sets when environments hibernate.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes hibernation.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Set how long without requests the environment waits before hibernating, or null to keep it running. Turning it
     * on needs `deploy.hibernation`; the idle clock starts now.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  int|null  $minutes  one of MINUTES, or null
     * @return void
     */
    public function handle(User $actor, Environment $environment, ?int $minutes): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if ($minutes !== null && ! in_array($minutes, self::MINUTES, true)) {
            throw ValidationException::withMessages(['hibernate_after_minutes' => __('Choose one of the offered idle times.')]);
        }
        if ($minutes !== null && ! $this->entitlements->for($environment->project->account)->has('deploy.hibernation')) {
            throw ValidationException::withMessages(['hibernate_after_minutes' => __('Hibernation comes with the Starter Deploy plan and above.')]);
        }
        $environment->forceFill(['hibernate_after_minutes' => $minutes, 'last_activity_at' => now()])->save();
    }
}
