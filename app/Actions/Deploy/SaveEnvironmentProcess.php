<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveEnvironmentProcess
{
    /**
     * Adds or changes an environment's long-running process, within the plan.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes worker processes.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Add or change a worker or the scheduler (always one replica). Deploys (re)start them as systemd units.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{name: string, type: string, command: string, replicas: int, restart_policy: string, restart_delay_seconds: int, is_enabled: bool}  $data
     * @return EnvironmentProcess
     */
    public function handle(User $actor, Environment $environment, array $data): EnvironmentProcess
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.workers')) {
            throw ValidationException::withMessages(['process' => __('Workers come with the Starter Deploy plan and above.')]);
        }
        $process = $environment->processes()->where('name', $data['name'])->first() ?? new EnvironmentProcess;
        $process->forceFill([...$data, 'environment_id' => $environment->id, 'replicas' => $data['type'] === 'scheduler' ? 1 : $data['replicas']])->save();

        return $process;
    }
}
