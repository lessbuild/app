<?php

namespace App\Modules\Deployer\Actions\Automation;

use App\Modules\Deployer\Models\DeploymentSchedule;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;

class CreateDeploymentScheduleAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Create an enabled deployment schedule for an entitled environment.
     *
     * @param  Environment  $environment  Environment already authorized for update.
     * @param  User  $actor  User recorded as the schedule creator.
     * @param  array{name: string, cron_expression: string, timezone: string}  $attributes  Validated schedule attributes.
     * @return DeploymentSchedule The enabled schedule.
     */
    public function handle(Environment $environment, User $actor, array $attributes): DeploymentSchedule
    {
        $this->entitlements->enforce($environment->project->organization, 'scheduled_deployments');

        return $environment->deploymentSchedules()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'is_enabled' => true,
        ]);
    }
}
