<?php

namespace App\Services;

use App\Models\EnvironmentProcess;
use App\Models\Project;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowConfiguration
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function apply(Project $project, string $yaml, int $userId): void
    {
        if (strlen($yaml) > 50000) {
            $this->invalid('Workflow YAML may not exceed 50 KB.');
        }
        try {
            $document = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            $this->invalid('Invalid YAML: '.$exception->getMessage());
        }
        if (! is_array($document) || ($document['version'] ?? null) !== 1 || ! is_array($document['environments'] ?? null)) {
            $this->invalid('Workflow must contain version: 1 and an environments map.');
        }

        DB::transaction(function () use ($project, $document, $yaml, $userId): void {
            foreach ($document['environments'] as $slug => $settings) {
                if (! is_string($slug) || ! is_array($settings)) {
                    $this->invalid('Each environment must be a named map.');
                }
                $environment = $project->environments()->where('slug', $slug)->first();
                if (! $environment) {
                    $this->invalid("Unknown environment: {$slug}.");
                }

                if (isset($settings['deployment'])) {
                    $this->entitlements->enforce($project->organization, 'scheduled_deployments');
                    $deployment = $settings['deployment'];
                    $this->validateSchedule($deployment);
                    $environment->deploymentSchedules()->updateOrCreate(['name' => 'Workflow schedule'], [
                        'created_by' => $userId,
                        'cron_expression' => $deployment['cron'],
                        'timezone' => $deployment['timezone'] ?? 'UTC',
                        'is_enabled' => (bool) ($deployment['enabled'] ?? true),
                    ]);
                }

                if (isset($settings['scale'])) {
                    $this->entitlements->enforce($project->organization, 'scaling');
                    $scale = $settings['scale'];
                    if (! is_array($scale)) {
                        $this->invalid("Scale settings for {$slug} must be a map.");
                    }
                    $minimum = filter_var($scale['minimum'] ?? 1, FILTER_VALIDATE_INT);
                    $maximum = filter_var($scale['maximum'] ?? 1, FILTER_VALIDATE_INT);
                    $desired = filter_var($scale['desired'] ?? $minimum, FILTER_VALIDATE_INT);
                    $hibernate = $scale['hibernate_after_minutes'] ?? null;
                    if ($minimum === false || $maximum === false || $desired === false || $minimum < 1 || $maximum > 20 || $maximum < $minimum || $desired < $minimum || $desired > $maximum
                        || (! is_null($hibernate) && ! in_array((int) $hibernate, [5, 15, 30, 60, 120, 1440], true))) {
                        $this->invalid("Invalid scaling range for {$slug}.");
                    }
                    $environment->update(['minimum_replicas' => $minimum, 'maximum_replicas' => $maximum, 'desired_replicas' => $desired, 'hibernate_after_minutes' => $hibernate]);
                }

                if (isset($settings['scaling_schedules'])) {
                    $this->entitlements->enforce($project->organization, 'scheduled_scaling');
                    if (! is_array($settings['scaling_schedules'])) {
                        $this->invalid("Scaling schedules for {$slug} must be a list.");
                    }
                    $environment->scalingSchedules()->where('name', 'like', 'Workflow:%')->delete();
                    foreach ($settings['scaling_schedules'] as $index => $schedule) {
                        $this->validateSchedule($schedule);
                        $replicas = filter_var($schedule['replicas'] ?? null, FILTER_VALIDATE_INT);
                        if ($replicas === false || $replicas < $environment->minimum_replicas || $replicas > $environment->maximum_replicas) {
                            $this->invalid("Invalid replicas in scaling schedule {$index}.");
                        }
                        $environment->scalingSchedules()->create([
                            'created_by' => $userId, 'name' => 'Workflow: '.($schedule['name'] ?? $index + 1),
                            'replicas' => $replicas, 'cron_expression' => $schedule['cron'],
                            'timezone' => $schedule['timezone'] ?? 'UTC', 'is_enabled' => (bool) ($schedule['enabled'] ?? true),
                        ]);
                    }
                }

                if (isset($settings['processes'])) {
                    $this->entitlements->enforce($project->organization, 'workers');
                    if (! is_array($settings['processes'])) {
                        $this->invalid("Processes for {$slug} must be a map.");
                    }
                    foreach ($settings['processes'] as $name => $process) {
                        if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', (string) $name) || ! is_array($process)
                            || ! in_array($process['type'] ?? null, EnvironmentProcess::TYPES, true) || ! is_string($process['command'] ?? null)) {
                            $this->invalid("Invalid process definition: {$name}.");
                        }
                        $environment->processes()->updateOrCreate(['name' => $name], [
                            'type' => $process['type'], 'command' => $process['command'],
                            'replicas' => $process['type'] === 'scheduler' ? 1 : max(1, min(20, (int) ($process['replicas'] ?? 1))),
                            'is_enabled' => (bool) ($process['enabled'] ?? true),
                        ]);
                    }
                }
            }
            $project->update(['workflow_yaml' => $yaml]);
        });
    }

    private function validateSchedule(mixed $schedule): void
    {
        if (! is_array($schedule) || ! is_string($schedule['cron'] ?? null) || ! CronExpression::isValidExpression($schedule['cron'])
            || ! in_array($schedule['timezone'] ?? 'UTC', timezone_identifiers_list(), true)) {
            $this->invalid('Schedules need a valid cron expression and IANA timezone.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => __($message)]);
    }
}
