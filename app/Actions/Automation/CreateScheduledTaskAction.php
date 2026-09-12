<?php

namespace App\Actions\Automation;

use App\Models\Environment;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Services\Entitlements;

class CreateScheduledTaskAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Persist an enabled, encrypted-command scheduled task for an entitled environment.
     *
     * @param  Environment  $environment  Environment already authorized for update.
     * @param  User  $actor  User recorded as the task creator.
     * @param  array{name: string, cron_expression: string, timezone: string, command: string, timeout_seconds: int|string, without_overlapping: bool|int|string, alert_on_failure: bool|int|string}  $attributes  Validated task attributes.
     * @return ScheduledTask The enabled task.
     */
    public function handle(Environment $environment, User $actor, array $attributes): ScheduledTask
    {
        $this->entitlements->enforce($environment->project->organization, 'scheduled_deployments');

        return $environment->scheduledTasks()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'is_enabled' => true,
        ]);
    }
}
