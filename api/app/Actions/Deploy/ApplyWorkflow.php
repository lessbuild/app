<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\Project;
use App\Models\ScalingSchedule;
use App\Models\User;
use App\Services\Billing\AccountEntitlements;
use App\Services\Billing\Entitlements;
use App\Support\Cron;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

final class ApplyWorkflow
{
    /**
     * Create a new ApplyWorkflow instance.
     *
     * Applies Deployer's version 1 workflow documents.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes what each section uses.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Apply a version 1 workflow document (Deployer's format, at most 50 KB) to the project's environments in one
     * transaction, and keep it on the project. Per environment slug it can set a scheduled deploy (`deployment`),
     * replicas and hibernation (`scale`), scaling schedules (`scaling_schedules`, replacing earlier workflow ones) and
     * processes (`processes`). Any error rejects the whole document.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $yaml
     * @return void
     */
    public function handle(User $actor, Project $project, string $yaml): void
    {
        Gate::forUser($actor)->authorize('manageDeploy', $project);
        if (strlen($yaml) > 50000) {
            $this->invalid(__('The workflow may be at most 50 KB.'));
        }
        try {
            $document = (new Parser(maxNestingLevel: 8, maxAliasesForCollections: 0))->parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (ParseException) {
            $this->invalid(__('The workflow isn’t valid YAML.'));
        }
        if (! is_array($document) || ($document['version'] ?? null) !== 1 || ! is_array($document['environments'] ?? null)) {
            $this->invalid(__('The workflow needs version: 1 and an environments map.'));
        }
        $entitlements = $this->entitlements->for($project->account);
        $scaled = DB::transaction(function () use ($actor, $project, $document, $yaml, $entitlements): array {
            $scaled = [];
            foreach ($document['environments'] as $slug => $settings) {
                $environment = is_string($slug) ? $project->environments()->where('slug', $slug)->first() : null;
                if ($environment === null || ! is_array($settings)) {
                    $this->invalid(__('Unknown environment: :slug.', ['slug' => is_string($slug) ? $slug : '?']));
                }
                if (array_key_exists('deployment', $settings)) {
                    $this->deployment($actor, $environment, $settings['deployment'], $entitlements);
                }
                if (array_key_exists('scale', $settings)) {
                    $this->scale($environment, $settings['scale'], $entitlements);
                    $scaled[] = $environment;
                }
                if (array_key_exists('scaling_schedules', $settings)) {
                    $this->scalingSchedules($actor, $environment, $settings['scaling_schedules'], $entitlements);
                }
                if (array_key_exists('processes', $settings)) {
                    $this->processes($environment, $settings['processes'], $entitlements);
                }
            }
            $project->forceFill(['workflow_document' => $yaml])->save();

            return $scaled;
        });
        foreach ($scaled as $environment) {
            if ($environment->hibernated_at === null) {
                ApplyEnvironmentRuntime::dispatch($environment->id, false);
            }
        }
    }

    /**
     * Set the environment's workflow deploy schedule (the one named "Workflow schedule").
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  mixed  $deployment  cron, timezone and enabled
     * @param  AccountEntitlements  $entitlements
     * @return void
     */
    private function deployment(User $actor, Environment $environment, mixed $deployment, AccountEntitlements $entitlements): void
    {
        if (! $entitlements->has('deploy.scheduled')) {
            $this->invalid(__('Scheduled deploys come with the Pro Deploy plan and above.'));
        }
        [$cron, $timezone, $enabled] = $this->schedule($deployment);
        $schedule = $environment->deploymentSchedules()->where('name', 'Workflow schedule')->first() ?? new DeploymentSchedule;
        $schedule->forceFill(['environment_id' => $environment->id, 'created_by' => $actor->id, 'name' => 'Workflow schedule', 'cron_expression' => $cron, 'timezone' => $timezone, 'is_enabled' => $enabled])->save();
    }

    /**
     * Set the environment's replica range, running replicas and hibernation.
     *
     * @param  Environment  $environment
     * @param  mixed  $scale  minimum, maximum, desired and hibernate_after_minutes
     * @param  AccountEntitlements  $entitlements
     * @return void
     */
    private function scale(Environment $environment, mixed $scale, AccountEntitlements $entitlements): void
    {
        if (! is_array($scale)) {
            $this->invalid(__('Scale settings for :slug must be a map.', ['slug' => $environment->slug]));
        }
        $minimum = filter_var($scale['minimum'] ?? 1, FILTER_VALIDATE_INT);
        $maximum = filter_var($scale['maximum'] ?? 1, FILTER_VALIDATE_INT);
        $desired = filter_var($scale['desired'] ?? $minimum, FILTER_VALIDATE_INT);
        $hibernate = $scale['hibernate_after_minutes'] ?? null;
        if ($minimum === false || $maximum === false || $desired === false || $minimum < 1 || $maximum > 20 || $maximum < $minimum || $desired < $minimum || $desired > $maximum
            || ($hibernate !== null && ! in_array($hibernate, UpdateEnvironmentHibernation::MINUTES, true))) {
            $this->invalid(__('Invalid scaling for :slug.', ['slug' => $environment->slug]));
        }
        if ($maximum > 1 && ! $entitlements->has('deploy.scaling')) {
            $this->invalid(__('Scaling comes with the Business Deploy plan and above.'));
        }
        if ($hibernate !== null && ! $entitlements->has('deploy.hibernation')) {
            $this->invalid(__('Hibernation comes with the Starter Deploy plan and above.'));
        }
        $environment->forceFill(['minimum_replicas' => $minimum, 'maximum_replicas' => $maximum, 'desired_replicas' => $desired, 'hibernate_after_minutes' => $hibernate])->save();
    }

    /**
     * Replace the environment's workflow scaling schedules (those named "Workflow: …").
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  mixed  $schedules  a list of name, replicas, cron, timezone and enabled
     * @param  AccountEntitlements  $entitlements
     * @return void
     */
    private function scalingSchedules(User $actor, Environment $environment, mixed $schedules, AccountEntitlements $entitlements): void
    {
        if (! $entitlements->has('deploy.scaling')) {
            $this->invalid(__('Scaling comes with the Business Deploy plan and above.'));
        }
        if (! is_array($schedules) || ! array_is_list($schedules) || count($schedules) > 20) {
            $this->invalid(__('Scaling schedules for :slug must be a list of at most 20.', ['slug' => $environment->slug]));
        }
        $environment->scalingSchedules()->where('name', 'like', 'Workflow:%')->delete();
        foreach ($schedules as $index => $entry) {
            [$cron, $timezone, $enabled] = $this->schedule($entry);
            $replicas = is_array($entry) ? filter_var($entry['replicas'] ?? null, FILTER_VALIDATE_INT) : false;
            if (! is_array($entry) || $replicas === false || $replicas < $environment->minimum_replicas || $replicas > $environment->maximum_replicas) {
                $this->invalid(__('Invalid replicas in scaling schedule :number.', ['number' => $index + 1]));
            }
            $name = is_string($entry['name'] ?? null) ? mb_substr($entry['name'], 0, 80) : (string) ($index + 1);
            $schedule = new ScalingSchedule;
            $schedule->forceFill(['environment_id' => $environment->id, 'created_by' => $actor->id, 'name' => 'Workflow: '.$name, 'replicas' => $replicas, 'cron_expression' => $cron, 'timezone' => $timezone, 'is_enabled' => $enabled])->save();
        }
    }

    /**
     * Create or update the environment's processes by name.
     *
     * @param  Environment  $environment
     * @param  mixed  $processes  a map of name => type, command, replicas and enabled
     * @param  AccountEntitlements  $entitlements
     * @return void
     */
    private function processes(Environment $environment, mixed $processes, AccountEntitlements $entitlements): void
    {
        if (! $entitlements->has('deploy.workers')) {
            $this->invalid(__('Workers come with the Starter Deploy plan and above.'));
        }
        if (! is_array($processes) || count($processes) > 20) {
            $this->invalid(__('Processes for :slug must be a map of at most 20.', ['slug' => $environment->slug]));
        }
        foreach ($processes as $name => $process) {
            if (! is_string($name) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,59}\z/', $name) !== 1 || ! is_array($process)
                || ! in_array($process['type'] ?? null, ['worker', 'scheduler'], true) || ! is_string($process['command'] ?? null)
                || $process['command'] === '' || strlen($process['command']) > 2000 || preg_match('/[\x00-\x1F]/', $process['command']) === 1) {
                $this->invalid(__('Invalid process definition: :name.', ['name' => is_string($name) ? $name : '?']));
            }
            $record = $environment->processes()->where('name', $name)->first() ?? new EnvironmentProcess;
            $record->forceFill([
                'environment_id' => $environment->id, 'name' => $name, 'type' => $process['type'], 'command' => $process['command'],
                'replicas' => $process['type'] === 'scheduler' ? 1 : max(1, min(20, (int) ($process['replicas'] ?? 1))),
                'restart_policy' => $record->restart_policy ?? 'always', 'restart_delay_seconds' => $record->restart_delay_seconds ?? 5,
                'is_enabled' => (bool) ($process['enabled'] ?? true),
            ])->save();
        }
    }

    /**
     * Read a schedule's cron expression, time zone (UTC by default) and enabled flag (on by default).
     *
     * @param  mixed  $schedule
     * @return array{string, string, bool}
     */
    private function schedule(mixed $schedule): array
    {
        $cron = is_array($schedule) && is_string($schedule['cron'] ?? null) ? $schedule['cron'] : '';
        $timezone = is_array($schedule) && is_string($schedule['timezone'] ?? 'UTC') ? (string) ($schedule['timezone'] ?? 'UTC') : '';
        if (! Cron::valid($cron, $timezone)) {
            $this->invalid(__('Schedules need a valid cron expression and IANA time zone.'));
        }

        return [$cron, $timezone, is_array($schedule) ? (bool) ($schedule['enabled'] ?? true) : true];
    }

    /**
     * Reject the workflow with a message under the `workflow` field.
     *
     * @param  string  $message
     * @return never
     */
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }
}
