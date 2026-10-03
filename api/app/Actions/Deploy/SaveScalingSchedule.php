<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\ScalingSchedule;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Cron;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveScalingSchedule
{
    /**
     * Create a new SaveScalingSchedule instance.
     *
     * Adds scaling schedules.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes scaling.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Schedule a replica count for the environment on a cron expression in a time zone, within its minimum and
     * maximum. Needs `deploy.scaling`.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{name: string, replicas: int, cron_expression: string, timezone: string, is_enabled: bool}  $data
     * @return ScalingSchedule
     */
    public function handle(User $actor, Environment $environment, array $data): ScalingSchedule
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.scaling')) {
            throw ValidationException::withMessages(['schedule' => __('Scaling comes with the Business Deploy plan and above.')]);
        }
        if (! Cron::valid($data['cron_expression'], $data['timezone'])) {
            throw ValidationException::withMessages(['cron_expression' => __('Use a five-field cron expression, like 0 3 * * 1, and a time zone like Europe/London.')]);
        }
        if ($data['replicas'] < $environment->minimum_replicas || $data['replicas'] > $environment->maximum_replicas) {
            throw ValidationException::withMessages(['replicas' => __('Choose between :min and :max replicas.', ['min' => $environment->minimum_replicas, 'max' => $environment->maximum_replicas])]);
        }
        $schedule = new ScalingSchedule;
        $schedule->forceFill(['environment_id' => $environment->id, 'created_by' => $actor->id, ...$data])->save();

        return $schedule;
    }
}
