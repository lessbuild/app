<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Analytics\SaveGoal;
use App\Actions\Analytics\SaveSite;
use App\Actions\Deploy\SaveRepository;
use App\Actions\Deploy\UpdateEnvironmentDeploySettings;
use App\Actions\Infrastructure\CreateWebsite;
use App\Actions\Monitoring\SaveMonitor;
use App\Data\Analytics\GoalDetails;
use App\Data\Analytics\SiteDetails;
use App\Data\Projects\ProjectDetails;
use App\Enums\EnvironmentKind;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectTemplates;
use Illuminate\Validation\ValidationException;

final class CreateProjectFromTemplate
{
    /**
     * Create a new CreateProjectFromTemplate instance.
     *
     * @param  CreateProject  $projects  Creates the project.
     * @param  EnableService  $services  Turns on the template's services.
     * @param  UpdateEnvironmentDeploySettings  $settings  Sets production's runtime and build.
     * @param  CreateWebsite  $websites  Creates and provisions the website (with its health monitor).
     * @param  SaveRepository  $repositories  Connects the repository.
     * @param  SaveSite  $sites  Adds the Analytics site.
     * @param  ProjectTemplates  $templates  Finds built-in and saved templates.
     * @param  CreateEnvironment  $environments  Adds a saved template's other environments.
     * @param  SaveMonitor  $monitors  Adds a saved template's uptime checks.
     * @param  SaveGoal  $goals  Adds a saved template's Analytics goals.
     */
    public function __construct(
        private readonly CreateProject $projects,
        private readonly EnableService $services,
        private readonly UpdateEnvironmentDeploySettings $settings,
        private readonly CreateWebsite $websites,
        private readonly SaveRepository $repositories,
        private readonly SaveSite $sites,
        private readonly ProjectTemplates $templates,
        private readonly CreateEnvironment $environments,
        private readonly SaveMonitor $monitors,
        private readonly SaveGoal $goals,
    ) {}

    /**
     * Set up a whole project from a template (built in, or saved from one of the account's projects): the project and
     * its services, production's runtime, a website on one of the account's app servers, the repository deploying to
     * it, and an Analytics site. A saved template also brings its other environments, release settings, uptime checks
     * (pointed at the new domain) and Analytics goals. Each step runs through its own action, with its own
     * permissions and plan limits.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $template  a key of config/templates.php, or saved-{id}
     * @param  array{name: string, server_id: int, domain: string, provider_id: int, repository_url: string, branch: string}  $data
     * @return Project
     */
    public function handle(User $actor, Account $account, string $template, array $data): Project
    {
        $definition = $this->templates->find($account->id, $template);
        if (! is_array($definition)) {
            throw ValidationException::withMessages(['template' => __('Choose one of the templates.')]);
        }
        $project = $this->projects->handle($actor, $account, new ProjectDetails($data['name'], (string) $definition['description']));
        foreach ((array) $definition['services'] as $service) {
            $this->services->handle($actor, $project, (string) $service);
        }
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $runtime = (array) $definition['runtime'];
        $this->settings->handle($actor, $production, [
            'requires_deployment_approval' => (bool) ($definition['settings']['requires_deployment_approval'] ?? false),
            'deployment_strategy' => (string) ($definition['settings']['deployment_strategy'] ?? 'blue_green'), 'rolling_pause_seconds' => 0,
            'automatic_rollback' => (bool) ($definition['settings']['automatic_rollback'] ?? true),
            'post_deployment_observation_minutes' => $definition['settings']['post_deployment_observation_minutes'] ?? 5,
            'runtime_type' => (string) $runtime['type'], 'runtime_version' => null,
            'build_command' => $runtime['build_command'], 'start_command' => $runtime['start_command'],
            'container_port' => $runtime['container_port'], 'dockerfile_path' => null,
            'minimum_replicas' => 1, 'maximum_replicas' => 1, 'desired_replicas' => 1,
        ]);
        $website = $this->websites->handle($account, $actor, [
            'name' => $data['name'], 'server_id' => $data['server_id'], 'url' => $data['domain'], 'environment_id' => $production->id,
            'env_file' => (string) $definition['env'], 'health_check_enabled' => true, 'health_check_path' => (string) $definition['health_check_path'],
            'health_monitoring_enabled' => true,
        ]);
        $this->repositories->handle($actor, $project, [
            'name' => $data['name'], 'provider_id' => $data['provider_id'], 'url' => $data['repository_url'], 'branch' => $data['branch'],
            'website_id' => $website->id, 'environment_id' => $production->id,
            'build_commands' => $definition['build_commands'], 'post_deployment_commands' => $definition['post_deployment_commands'],
        ]);
        if ($definition['analytics'] ?? false) {
            $site = $this->sites->handle($actor, $project, new SiteDetails($data['name'], [$data['domain']], 'UTC', [], $production->id));
            foreach ((array) ($definition['goals'] ?? []) as $goal) {
                $this->goals->handle($actor, $site, new GoalDetails((string) $goal['name'], (string) $goal['kind'], (string) $goal['match_type'], (string) $goal['match_value']));
            }
        }
        foreach ((array) ($definition['environments'] ?? []) as $environment) {
            $this->environments->handle($actor, $project, (string) $environment['name'], EnvironmentKind::from((string) $environment['kind']));
        }
        if (in_array('monitoring', (array) $definition['services'], true)) {
            foreach ((array) ($definition['monitors'] ?? []) as $monitor) {
                $this->monitors->handle($project, $actor, [
                    'environment_id' => $production->id, 'check_type' => 'http', 'name' => (string) $monitor['name'], 'enabled' => true,
                    'request_url' => 'https://'.$data['domain'].(string) $monitor['path'],
                    ...array_intersect_key($monitor, array_flip(['method', 'status_min', 'status_max', 'body_contains', 'timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks'])),
                ]);
            }
        }

        return $project;
    }
}
