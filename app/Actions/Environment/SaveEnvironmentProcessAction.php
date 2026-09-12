<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\EnvironmentProcess;

class SaveEnvironmentProcessAction
{
    /**
     * Persist one process definition for the next environment deployment.
     *
     * @param  array{name: string, type: string, command: string, replicas: int|string, restart_policy: string, restart_delay_seconds: int|string, is_enabled: bool|string}  $data
     */
    public function handle(Environment $environment, array $data): EnvironmentProcess
    {
        if ($data['type'] === 'scheduler') {
            $data['replicas'] = 1;
        }

        return $environment->processes()->updateOrCreate(['name' => $data['name']], $data);
    }
}
