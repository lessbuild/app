<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\EnvironmentKind;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsSite;
use App\Models\Environment;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;

final class SaveProjectTemplate
{
    /**
     * Save a project as a template for new projects: its services, other environments, how production builds, runs
     * and releases, its HTTP uptime checks on its own domain and its Analytics goals. Variable names come along with
     * empty values; secrets, domains and servers never do.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $name
     * @param  string|null  $description
     * @return ProjectTemplate
     */
    public function handle(User $actor, Project $project, string $name, ?string $description): ProjectTemplate
    {
        Gate::forUser($actor)->authorize('update', $project);
        Gate::forUser($actor)->authorize('create', [Project::class, $project->account]);
        $environments = $project->environments()->get();
        $production = $environments->first(fn (Environment $environment): bool => $environment->kind === EnvironmentKind::Production) ?? $environments->first();
        $website = $production === null ? null : Website::query()->where('environment_id', $production->id)->orderBy('id')->first();
        $repository = Repository::query()->where('project_id', $project->id)->where('environment_id', $production?->id)->orderBy('id')->first();
        $site = AnalyticsSite::query()->where('project_id', $project->id)->orderBy('id')->first();

        $definition = [
            'services' => $project->enabledServices()->pluck('service')->values()->all(),
            'runtime' => [
                'type' => $production->runtime_type ?? 'php', 'build_command' => $production?->build_command,
                'start_command' => $production?->start_command, 'container_port' => $production?->container_port,
            ],
            'settings' => $production === null ? [] : [
                'requires_deployment_approval' => $production->requires_deployment_approval, 'deployment_strategy' => $production->deployment_strategy,
                'automatic_rollback' => $production->automatic_rollback, 'post_deployment_observation_minutes' => $production->post_deployment_observation_minutes,
            ],
            'build_commands' => $repository?->build_commands,
            'post_deployment_commands' => $repository?->post_deployment_commands,
            'health_check_path' => $website->health_check_path ?? '/',
            'env' => $this->variableNames((string) $website?->env_file),
            'analytics' => $site !== null,
            'environments' => $environments->filter(fn (Environment $environment): bool => $environment->id !== $production?->id && $environment->kind !== EnvironmentKind::Preview)
                ->map(fn (Environment $environment): array => ['name' => $environment->name, 'kind' => $environment->kind->value])->values()->all(),
            'monitors' => $production === null || $website === null ? [] : Monitor::query()->where('environment_id', $production->id)->where('type', 'http')
                // The website's own health check is made again with the new website.
                ->when($website->health_monitor_id !== null, fn ($query) => $query->whereKeyNot($website->health_monitor_id))->get()
                ->map(fn (Monitor $monitor): ?array => $this->monitor($monitor, $website->url))->filter()->values()->all(),
            'goals' => $site === null ? [] : AnalyticsGoal::query()->where('site_id', $site->id)->where('active', true)->orderBy('id')->get()
                ->map(fn (AnalyticsGoal $goal): array => ['name' => $goal->name, 'kind' => $goal->kind, 'match_type' => $goal->match_type, 'match_value' => $goal->match_value])->all(),
        ];
        $template = new ProjectTemplate;
        $template->forceFill([
            'account_id' => $project->account_id, 'created_by' => $actor->id, 'name' => trim($name),
            'description' => trim((string) $description) !== '' ? trim((string) $description) : __('Saved from :project.', ['project' => $project->name]),
            'definition' => $definition,
        ])->save();

        return $template;
    }

    /**
     * Keep an environment file's variable names with empty values, dropping comments and blank lines.
     *
     * @param  string  $env
     * @return string
     */
    private function variableNames(string $env): string
    {
        $names = [];
        foreach (preg_split('/\R/', $env) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) === 1) {
                $names[] = $match[1].'=';
            }
        }

        return $names === [] ? '' : implode("\n", array_unique($names))."\n";
    }

    /**
     * Describe an HTTP check on the website's own domain by its path, so it can point at a new project's domain;
     * checks of other addresses aren't kept.
     *
     * @param  Monitor  $monitor
     * @param  string  $host
     * @return array<string, mixed>|null
     */
    private function monitor(Monitor $monitor, string $host): ?array
    {
        $parts = parse_url((string) $monitor->request_url);
        if (! is_array($parts) || strcasecmp((string) ($parts['host'] ?? ''), $host) !== 0) {
            return null;
        }

        return [
            'name' => $monitor->name, 'path' => ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''), 'method' => $monitor->method,
            'status_min' => $monitor->status_min, 'status_max' => $monitor->status_max, 'body_contains' => $monitor->body_contains,
            'timeout_seconds' => $monitor->timeout_seconds, 'interval_minutes' => $monitor->interval_minutes,
            'trigger_checks' => $monitor->trigger_checks, 'recovery_checks' => $monitor->recovery_checks,
        ];
    }
}
