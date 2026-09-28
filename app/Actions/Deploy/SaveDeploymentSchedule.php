<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Cron;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveDeploymentSchedule
{
    /**
     * Create a new SaveDeploymentSchedule instance.
     *
     * Adds scheduled deploys.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes scheduled deploys.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Schedule deploys of the environment's repositories on a cron expression in a time zone. Needs `deploy.scheduled`.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{name: string, cron_expression: string, timezone: string, is_enabled: bool}  $data
     * @return DeploymentSchedule
     */
    public function handle(User $actor, Environment $environment, array $data): DeploymentSchedule
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.scheduled')) {
            throw ValidationException::withMessages(['schedule' => __('Scheduled deploys come with the Pro Deploy plan and above.')]);
        }
        if (! Cron::valid($data['cron_expression'], $data['timezone'])) {
            throw ValidationException::withMessages(['cron_expression' => __('Use a five-field cron expression, like 0 3 * * 1, and a time zone like Europe/London.')]);
        }
        $schedule = new DeploymentSchedule;
        $schedule->forceFill(['environment_id' => $environment->id, 'created_by' => $actor->id, ...$data])->save();

        return $schedule;
    }
}
