<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use Illuminate\Database\Eloquent\Collection;

/** An environment's Automation tab: its schedules, tasks with their recent runs, and what the plan allows. */
final class EnvironmentAutomationQuery
{
    /**
     * Create a new EnvironmentAutomationQuery instance.
     *
     * Reads the Automation tab.
     *
     * @param  Entitlements  $entitlements  Reads which automation the plan includes.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Get the environment's scheduled deploys, scaling schedules and tasks (each with its five latest runs), the
     * websites a task can run in, and whether the plan includes scheduling, scaling and hibernation.
     *
     * @param  Environment  $environment
     * @return array{deploySchedules: Collection<int, DeploymentSchedule>, scalingSchedules: Collection<int, ScalingSchedule>, tasks: Collection<int, ScheduledTask>, taskWebsites: Collection<int, Website>, plan: array{scheduled: bool, scaling: bool, hibernation: bool}}
     */
    public function handle(Environment $environment): array
    {
        $entitlements = $this->entitlements->for($environment->project->account);

        return [
            'deploySchedules' => $environment->deploymentSchedules()->orderBy('name')->get(),
            'scalingSchedules' => $environment->scalingSchedules()->orderBy('name')->get(),
            'tasks' => $environment->scheduledTasks()->with(['website', 'runs' => fn ($query) => $query->latest('id')->limit(5)])->orderBy('name')->get(),
            'taskWebsites' => $environment->deployedWebsites(),
            'plan' => ['scheduled' => $entitlements->has('deploy.scheduled'), 'scaling' => $entitlements->has('deploy.scaling'), 'hibernation' => $entitlements->has('deploy.hibernation')],
        ];
    }
}
