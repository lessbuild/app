<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\EnvironmentKind;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentRecipe;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\ScalingSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CloneEnvironment
{
    /**
     * The deploy and runtime settings copied from the source.
     *
     * @var list<string>
     */
    private const SETTINGS = [
        'requires_deployment_approval', 'require_variable_approval', 'deployment_window_days', 'deployment_window_start', 'deployment_window_end', 'deployment_window_timezone',
        'deployment_strategy', 'rolling_pause_seconds', 'automatic_rollback', 'post_deployment_observation_minutes', 'rollback_error_rate_percent', 'rollback_latency_percent',
        'rollback_conversion_drop_percent', 'runtime_type', 'runtime_version', 'build_command', 'start_command', 'container_port', 'dockerfile_path',
        'minimum_replicas', 'maximum_replicas', 'desired_replicas', 'security_gate', 'autoscale_enabled', 'autoscale_cpu_target', 'hibernate_after_minutes', 'recipes_run_on_new_websites',
    ];

    /**
     * Create a new CloneEnvironment instance.
     *
     * @param  CreateEnvironment  $create  Creates the new environment.
     */
    public function __construct(private readonly CreateEnvironment $create) {}

    /**
     * Create an environment from another one in the project: its deploy and runtime settings, workers, recipes and
     * variables (secret values only when asked; otherwise secret variables are left out), with its scaling and deploy
     * schedules copied switched off. Websites, scheduled tasks and managed resources belong to servers, so they
     * aren't copied. Returns the new environment and the secret keys left to set.
     *
     * @param  User  $actor
     * @param  Environment  $source
     * @param  string  $name
     * @param  EnvironmentKind  $kind
     * @param  bool  $copySecrets
     * @return array{environment: Environment, skipped_secrets: list<string>}
     */
    public function handle(User $actor, Environment $source, string $name, EnvironmentKind $kind, bool $copySecrets): array
    {
        Gate::forUser($actor)->authorize('configureDeploy', $source);
        $project = Project::query()->findOrFail($source->project_id);

        return DB::transaction(function () use ($actor, $source, $project, $name, $kind, $copySecrets): array {
            $environment = $this->create->handle($actor, $project, $name, $kind);
            $environment->forceFill($source->only(self::SETTINGS))->save();
            $skipped = [];
            foreach ($source->variables()->orderBy('key')->get() as $variable) {
                if ($variable->is_secret && ! $copySecrets) {
                    $skipped[] = $variable->key;

                    continue;
                }
                $copy = new EnvironmentVariable;
                $copy->forceFill(['environment_id' => $environment->id, 'key' => $variable->key, 'value' => $variable->value, 'is_secret' => $variable->is_secret, 'scope' => $variable->scope, 'current_version' => 1, 'updated_by' => $actor->id])->save();
                $copy->versions()->forceCreate(['created_by' => $actor->id, 'version' => 1, 'value' => $variable->value]);
            }
            foreach (EnvironmentProcess::query()->where('environment_id', $source->id)->get() as $process) {
                $process->replicate()->forceFill(['environment_id' => $environment->id])->save();
            }
            foreach (EnvironmentRecipe::query()->where('environment_id', $source->id)->orderBy('position')->get() as $recipe) {
                $recipe->replicate()->forceFill(['environment_id' => $environment->id, 'added_by' => $actor->id])->save();
            }
            foreach ([ScalingSchedule::class, DeploymentSchedule::class] as $model) {
                foreach ($model::query()->where('environment_id', $source->id)->get() as $schedule) {
                    $schedule->replicate(['last_run_at', 'last_result'])->forceFill(['environment_id' => $environment->id, 'is_enabled' => false, 'created_by' => $actor->id])->save();
                }
            }

            return ['environment' => $environment, 'skipped_secrets' => $skipped];
        });
    }
}
