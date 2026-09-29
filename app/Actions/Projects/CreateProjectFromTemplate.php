<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Analytics\SaveSite;
use App\Actions\Deploy\SaveRepository;
use App\Actions\Deploy\UpdateEnvironmentDeploySettings;
use App\Actions\Infrastructure\CreateWebsite;
use App\Data\Analytics\SiteDetails;
use App\Data\Projects\ProjectDetails;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
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
     */
    public function __construct(
        private readonly CreateProject $projects,
        private readonly EnableService $services,
        private readonly UpdateEnvironmentDeploySettings $settings,
        private readonly CreateWebsite $websites,
        private readonly SaveRepository $repositories,
        private readonly SaveSite $sites,
    ) {}

    /**
     * Set up a whole project from a template (config/templates.php): the project and its services, production's
     * runtime, a website on one of the account's app servers, the repository deploying to it, and an Analytics site.
     * Each step runs through its own action, with its own permissions and plan limits.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $template  a key of config/templates.php
     * @param  array{name: string, server_id: int, domain: string, provider_id: int, repository_url: string, branch: string}  $data
     * @return Project
     */
    public function handle(User $actor, Account $account, string $template, array $data): Project
    {
        $definition = config("templates.{$template}");
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
            'requires_deployment_approval' => false, 'deployment_strategy' => 'blue_green', 'rolling_pause_seconds' => 0,
            'automatic_rollback' => true, 'post_deployment_observation_minutes' => 5,
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
            $this->sites->handle($actor, $project, new SiteDetails($data['name'], [$data['domain']], 'UTC', [], $production->id));
        }

        return $project;
    }
}
