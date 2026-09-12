<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\User;

class UpdateDeploymentControlsAction
{
    /**
     * Persist deployment locks, maintenance windows and rollout controls for one environment.
     *
     * @param  array<string, mixed>  $data  Validated deployment-control attributes.
     */
    public function handle(Environment $environment, User $actor, array $data): Environment
    {
        $environment->update([
            'deployment_locked_at' => $data['deployment_locked'] ? ($environment->deployment_locked_at ?? now()) : null,
            'deployment_locked_by' => $data['deployment_locked'] ? $actor->id : null,
            'deployment_lock_reason' => $data['deployment_locked'] ? ($data['deployment_lock_reason'] ?: null) : null,
            'deployment_window_days' => $data['deployment_window_enabled'] ? array_values($data['deployment_window_days']) : null,
            'deployment_window_start' => $data['deployment_window_enabled'] ? $data['deployment_window_start'] : null,
            'deployment_window_end' => $data['deployment_window_enabled'] ? $data['deployment_window_end'] : null,
            'deployment_window_timezone' => $data['deployment_window_enabled'] ? $data['deployment_window_timezone'] : null,
            'deployment_strategy' => $data['deployment_strategy'],
            'rolling_pause_seconds' => $data['rolling_pause_seconds'],
            'automatic_rollback' => $data['automatic_rollback'],
        ]);

        return $environment;
    }
}
