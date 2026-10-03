<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Data\Projects\ProjectSetup;
use App\Data\Projects\SetupStep;
use App\Enums\ProviderType;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\EnvironmentVariable;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Closure;

/**
 * The setup guide: the path from an empty project to a deployed, monitored and measured one. Each step reads where the
 * project stands (so steps tick themselves off, including work finishing in the background) and points at the next
 * thing to do, sending people to turn a service on first when the step needs one.
 */
final class ProjectSetupQuery
{
    /**
     * Work out where the project stands on each step.
     *
     * @param  Project  $project
     * @return ProjectSetup
     */
    public function handle(Project $project): ProjectSetup
    {
        $environmentIds = $project->environments()->pluck('id')->all();
        $repositories = Repository::query()->where('project_id', $project->id)->orderBy('id')->get();
        $websites = Website::query()->where('account_id', $project->account_id)
            ->where(fn ($query) => $query->whereIn('environment_id', $environmentIds)->orWhereIn('id', $repositories->pluck('website_id')->filter()->all()))
            ->orderByDesc('id')->get();

        // The guide follows the services the project uses: Analytics on its own, say, needs no server. A project
        // with no services yet gets the whole path.
        $services = $project->enabledServices()->pluck('service')->all();
        $wants = fn (string ...$needed): bool => $services === [] || array_intersect($needed, $services) !== [];

        return new ProjectSetup(array_values(array_filter([
            $wants('infrastructure', 'deploy') ? $this->provider($project) : null,
            $wants('infrastructure', 'deploy') ? $this->server($project) : null,
            $wants('infrastructure', 'deploy') ? $this->website($project, $websites) : null,
            $wants('deploy') ? $this->repository($project, $repositories) : null,
            $wants('deploy') ? $this->deploy($project, $repositories) : null,
            $wants('monitoring') ? $this->monitoring($project, $environmentIds) : null,
            $wants('analytics') ? $this->analytics($project) : null,
        ])));
    }

    /**
     * Step 1: connect a cloud account for servers (or have imported one).
     *
     * @param  Project  $project
     * @return SetupStep
     */
    private function provider(Project $project): SetupStep
    {
        $provider = Provider::query()->where('account_id', $project->account_id)
            ->whereIn('type', array_map(fn (ProviderType $type): string => $type->value, array_filter(ProviderType::cases(), fn (ProviderType $type): bool => $type->hostsServers())))->first();
        $imported = $provider === null && Server::query()->where('account_id', $project->account_id)->exists();

        return new SetupStep('provider', __('Connect a cloud provider'), __('Connect your cloud account (DigitalOcean, Hetzner, AWS, Google Cloud, Azure and more) so servers are created in it. Already have a server? You can import it instead.'),
            $provider !== null || $imported ? SetupStep::DONE : SetupStep::TODO,
            $provider !== null ? $this->text(':name is connected.', ['name' => $provider->name]) : ($imported ? $this->text('Using an imported server.') : null),
            __('Add a provider'), route('account.providers', ['dialog' => 'add-provider', 'return' => route('projects.setup', $project, false)]), 'layers');
    }

    /**
     * Step 2: create a server, and follow it while it's set up.
     *
     * @param  Project  $project
     * @return SetupStep
     */
    private function server(Project $project): SetupStep
    {
        $servers = Server::query()->where('account_id', $project->account_id);
        $active = (clone $servers)->where('provisioning_status', Server::STATUS_ACTIVE)->latest('id')->first();
        $pending = $active === null ? (clone $servers)->whereIn('provisioning_status', Server::PROVISIONING_STATUSES)->latest('id')->first() : null;
        [$label, $url] = $this->serviceAction($project, 'infrastructure', __('Create a server'), fn (): string => route('infrastructure.servers.create', $project));

        return match (true) {
            $active !== null => new SetupStep('server', __('Create a server'), __('A server in your cloud account runs your websites.'), SetupStep::DONE, __(':server is active.', ['server' => $active->label()]), __('Open it'), route('infrastructure.servers.show', [$project, $active->id]), 'server'),
            $pending !== null => new SetupStep('server', __('Create a server'), __('A server in your cloud account runs your websites.'), SetupStep::WORKING, __(':server is being set up. This usually takes a few minutes.', ['server' => $pending->label()]), __('Watch it live'), route('infrastructure.servers.show', [$project, $pending->id]), 'server'),
            default => new SetupStep('server', __('Create a server'), __('Pick a size and region; setup installs everything a website needs and runs by itself.'), SetupStep::TODO, null, $label, $url, 'server'),
        };
    }

    /**
     * Step 3: add a website to a server.
     *
     * @param  Project  $project
     * @param  \Illuminate\Support\Collection<int, Website>  $websites
     * @return SetupStep
     */
    private function website(Project $project, $websites): SetupStep
    {
        $active = $websites->firstWhere('provisioning_status', Website::STATUS_ACTIVE);
        $pending = $websites->first();
        [$label, $url] = $this->serviceAction($project, 'infrastructure', __('Add a website'), fn (): string => route('infrastructure.websites', [$project, 'dialog' => 'create-website']));

        return match (true) {
            $active !== null => new SetupStep('website', __('Add a website'), __('A website on the server, with its domain and certificate.'), SetupStep::DONE, __(':website is live.', ['website' => $active->name]), __('Open it'), route('infrastructure.websites.show', [$project, $active->id]), 'globe'),
            $pending !== null => new SetupStep('website', __('Add a website'), __('A website on the server, with its domain and certificate.'), SetupStep::WORKING, __(':website is being set up.', ['website' => $pending->name]), __('Open it'), route('infrastructure.websites.show', [$project, $pending->id]), 'globe'),
            default => new SetupStep('website', __('Add a website'), __('Choose the server and domain; point the domain’s DNS at the server and a certificate is issued for you.'), SetupStep::TODO, null, $label, $url, 'globe'),
        };
    }

    /**
     * Step 4: connect the Git repository and set up the environment it deploys to.
     *
     * @param  Project  $project
     * @param  \Illuminate\Support\Collection<int, Repository>  $repositories
     * @return SetupStep
     */
    private function repository(Project $project, $repositories): SetupStep
    {
        $repository = $repositories->first();
        if ($repository === null) {
            [$label, $url] = $this->serviceAction($project, 'deploy', __('Connect a repository'), fn (): string => route('deploy.repositories.create', $project));

            return new SetupStep('environment', __('Connect a repository and set up its environment'), __('Choose the repository, branch and website, then add the environment variables your app needs.'), SetupStep::TODO, null, $label, $url, 'code');
        }
        $variables = $repository->environment_id !== null ? EnvironmentVariable::query()->where('environment_id', $repository->environment_id)->count() : 0;

        return new SetupStep('environment', __('Connect a repository and set up its environment'), __('Choose the repository, branch and website, then add the environment variables your app needs.'), SetupStep::DONE,
            trans_choice(':repository is connected, with :count variable.|:repository is connected, with :count variables.', $variables, ['repository' => $repository->name, 'count' => $variables]),
            $repository->environment_id !== null ? __('Add a variable') : __('Open it'),
            $repository->environment_id !== null ? route('deploy.environments.show', [$project, $repository->environment_id, 'tab' => 'variables', 'dialog' => 'add-variable']) : route('deploy.repositories.show', [$project, $repository->id]), 'code');
    }

    /**
     * Step 5: deploy it.
     *
     * @param  Project  $project
     * @param  \Illuminate\Support\Collection<int, Repository>  $repositories
     * @return SetupStep
     */
    private function deploy(Project $project, $repositories): SetupStep
    {
        $builds = Build::query()->whereIn('repository_id', $repositories->pluck('id')->all());
        $succeeded = (clone $builds)->where('status', Build::STATUS_SUCCEEDED)->latest('id')->first();
        $running = $succeeded === null ? (clone $builds)->whereNotIn('status', Build::FINISHED)->latest('id')->first() : null;
        $repository = $repositories->first();

        return match (true) {
            $succeeded !== null => new SetupStep('deploy', __('Deploy'), __('Ship the code to the website.'), SetupStep::DONE, __('Deploy #:id is live.', ['id' => $succeeded->id]), __('See it'), route('deploy.builds.show', [$project, $succeeded->id]), 'cloud-upload'),
            $running !== null => new SetupStep('deploy', __('Deploy'), __('Ship the code to the website.'), SetupStep::WORKING, __('Deploy #:id is running.', ['id' => $running->id]), __('Follow it'), route('deploy.builds.show', [$project, $running->id]), 'cloud-upload'),
            default => new SetupStep('deploy', __('Deploy'), __('Deploy the branch to the website. Every deploy is kept, so you can roll back in seconds.'), SetupStep::TODO, null,
                $repository !== null ? $this->text('Deploy now') : null, $repository !== null ? route('deploy.repositories.show', [$project, $repository->id]) : null, 'cloud-upload'),
        };
    }

    /**
     * Step 6: watch the site with a monitor.
     *
     * @param  Project  $project
     * @param  array<array-key, mixed>  $environmentIds
     * @return SetupStep
     */
    private function monitoring(Project $project, array $environmentIds): SetupStep
    {
        $monitors = Monitor::query()->whereIn('environment_id', $environmentIds)->count();
        [$label, $url] = $this->serviceAction($project, 'monitoring', __('Add a monitor'), fn (): string => route('monitoring.monitors.create', $project));

        return $monitors > 0
            ? new SetupStep('monitor', __('Monitor it'), __('Get told when the site goes down, with incidents and a status page.'), SetupStep::DONE, trans_choice(':count monitor is watching.|:count monitors are watching.', $monitors, ['count' => $monitors]), __('Open Monitoring'), route('monitoring.monitors', $project), 'pulse')
            : new SetupStep('monitor', __('Monitor it'), __('Add an uptime check for the website, and choose who gets alerts.'), SetupStep::TODO, null, $label, $url, 'pulse');
    }

    /**
     * Step 7: measure visits with Analytics.
     *
     * @param  Project  $project
     * @return SetupStep
     */
    private function analytics(Project $project): SetupStep
    {
        $site = AnalyticsSite::query()->where('project_id', $project->id)->orderByRaw('last_event_at is null')->latest('last_event_at')->first();
        [$label, $url] = $this->serviceAction($project, 'analytics', __('Add a site'), fn (): string => route('analytics.sites', [$project, 'dialog' => 'add-site']));

        return match (true) {
            $site?->last_event_at !== null => new SetupStep('analytics', __('Measure visits'), __('See how people find and use the site, without cookies.'), SetupStep::DONE, __(':site is counting visits.', ['site' => $site->name]), __('Open Analytics'), route('analytics.sites.show', [$project, $site->id]), 'chart'),
            $site !== null => new SetupStep('analytics', __('Measure visits'), __('See how people find and use the site, without cookies.'), SetupStep::WORKING, __('Waiting for the first visit to :site. Add its snippet to your pages.', ['site' => $site->name]), __('Get the snippet'), route('analytics.sites.show', [$project, $site->id]), 'chart'),
            default => new SetupStep('analytics', __('Measure visits'), __('Add the site to Analytics and paste one line into your pages. No cookies, no personal data.'), SetupStep::TODO, null, $label, $url, 'chart'),
        };
    }

    /**
     * Get the button for a step: the step itself when its service is on, or turning the service on first.
     *
     * @param  Project  $project
     * @param  string  $service
     * @param  string  $label
     * @param  Closure(): string  $url
     * @return array{string, string}
     */
    private function serviceAction(Project $project, string $service, string $label, Closure $url): array
    {
        return $project->hasService($service)
            ? [$label, $url()]
            : [__('Turn on :service', ['service' => __(ucfirst($service))]), route('projects.services.show', [$project, $service])];
    }

    /**
     * Translate an optional line of the guide, always as text.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $replace
     * @return string
     */
    private function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
