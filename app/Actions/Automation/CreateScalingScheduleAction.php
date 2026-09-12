<?php

namespace App\Actions\Automation;

use App\Models\Environment;
use App\Models\ScalingSchedule;
use App\Models\User;
use App\Services\Entitlements;

class CreateScalingScheduleAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Create an enabled scaling schedule for an entitled environment.
     *
     * @param  Environment  $environment  Environment already authorized for update.
     * @param  User  $actor  User recorded as the schedule creator.
     * @param  array{name: string, cron_expression: string, timezone: string, replicas: int|string}  $attributes  Validated schedule attributes.
     * @return ScalingSchedule The enabled schedule.
     */
    public function handle(Environment $environment, User $actor, array $attributes): ScalingSchedule
    {
        $this->entitlements->enforce($environment->project->organization, 'scheduled_scaling');

        return $environment->scalingSchedules()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'is_enabled' => true,
        ]);
    }
}
