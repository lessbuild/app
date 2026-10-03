<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Cron;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveScheduledTask
{
    /**
     * Create a new SaveScheduledTask instance.
     *
     * Adds scheduled tasks.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes scheduled tasks.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Schedule a command in one of the websites the environment deploys to. Names are unique in the environment. Needs
     * `deploy.scheduled`.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{name: string, website_id: int, command: string, cron_expression: string, timezone: string, timeout_seconds: int, without_overlapping: bool, alert_on_failure: bool, is_enabled: bool}  $data
     * @return ScheduledTask
     */
    public function handle(User $actor, Environment $environment, array $data): ScheduledTask
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.scheduled')) {
            throw ValidationException::withMessages(['task' => __('Scheduled tasks come with the Pro Deploy plan and above.')]);
        }
        if (! Cron::valid($data['cron_expression'], $data['timezone'])) {
            throw ValidationException::withMessages(['cron_expression' => __('Use a five-field cron expression, like 0 3 * * 1, and a time zone like Europe/London.')]);
        }
        if (! $environment->deployedWebsites()->contains('id', $data['website_id'])) {
            throw ValidationException::withMessages(['website_id' => __('Choose a website one of this environment’s repositories deploys to.')]);
        }
        if ($environment->scheduledTasks()->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => __('This environment already has a task with that name.')]);
        }
        $task = new ScheduledTask;
        $task->forceFill(['environment_id' => $environment->id, 'created_by' => $actor->id, ...$data])->save();

        return $task;
    }
}
