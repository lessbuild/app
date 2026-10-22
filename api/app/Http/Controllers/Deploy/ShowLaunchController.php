<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Launch;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowLaunchController
{
    /**
     * Follow a launch: each step, then per app its website, deploy and a checklist (DNS pointing at the server, the
     * website answering, the deploy live), with links to each.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Launch  $launch
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Launch $launch, ProjectOverviewQuery $overview): JsonResponse
    {
        $result = $launch->result ?? [];
        $server = isset($result['server_id']) ? Server::query()->find((int) $result['server_id']) : null;
        $steps = array_keys(Launch::STEPS);
        $current = $launch->status === 'done' ? count($steps) : (int) array_search($launch->step ?? 'server', $steps, true);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'launch' => [
                'id' => $launch->id, 'status' => $launch->status, 'error' => $launch->error, 'active' => $launch->active(),
                'repository' => $launch->plan['repository']['url'] ?? '', 'branch' => $launch->plan['repository']['branch'] ?? '',
                'server' => $server === null ? null : ['label' => $server->label(), 'ip' => $server->public_ip, 'status' => $server->provisioning_status, 'url' => "/projects/{$project->id}/infrastructure/servers/{$server->id}"],
                'steps' => array_map(fn (string $step, int $index): array => [
                    'key' => $step, 'label' => __(Launch::STEPS[$step]),
                    'state' => match (true) {
                        $index < $current => 'done',
                        $index === $current => $launch->status === 'failed' ? 'failed' : 'current',
                        default => 'waiting',
                    },
                ], $steps, array_keys($steps)),
                'apps' => array_map(fn (mixed $app, int|string $index): array => $this->app($project, (array) $app, (array) ($result['apps'][$index] ?? []), $server), (array) $launch->plan['apps'], array_keys((array) $launch->plan['apps'])),
            ],
            'canRetry' => $launch->status === 'failed' && $user->can('create', [Repository::class, $project]),
        ]);
    }

    /**
     * Describe one app of the launch, with its checklist.
     *
     * @param  Project  $project
     * @param  array<string, mixed>  $app  the plan's app
     * @param  array<string, mixed>  $made  what the launch made for it
     * @param  Server|null  $server
     * @return array<string, mixed>
     */
    private function app(Project $project, array $app, array $made, ?Server $server): array
    {
        $website = isset($made['website_id']) ? Website::query()->find((int) $made['website_id']) : null;
        $build = isset($made['build_id']) ? Build::query()->find((int) $made['build_id']) : null;
        $domain = (string) $app['domain'];
        $addresses = $server?->public_ip !== null ? array_column(@dns_get_record($domain, DNS_A) ?: [], 'ip') : [];

        return [
            'name' => $app['name'], 'label' => $app['label'], 'domain' => $domain,
            'website' => $website === null ? null : ['status' => $website->provisioning_status, 'url' => "/projects/{$project->id}/infrastructure/websites/{$website->id}"],
            'repository' => isset($made['repository_id']) ? "/projects/{$project->id}/deploy/repositories/{$made['repository_id']}" : null,
            'environment' => isset($made['environment_id']) ? "/projects/{$project->id}/deploy/environments/{$made['environment_id']}" : null,
            'build' => $build === null ? null : ['status' => $build->status, 'url' => "/projects/{$project->id}/deploy/builds/{$build->id}"],
            'workflow' => (bool) ($app['githubActions'] ?? false) ? ['url' => $made['workflow'] ?? null, 'error' => $made['workflowError'] ?? null, 'waiting' => (bool) ($made['waitingForWorkflow'] ?? false)] : null,
            'checklist' => [
                ['key' => 'dns', 'label' => $server?->public_ip !== null ? __('Point :domain at :ip (an A record)', ['domain' => $domain, 'ip' => $server->public_ip]) : __('Point :domain at the server', ['domain' => $domain]), 'done' => $server?->public_ip !== null && in_array($server->public_ip, $addresses, true)],
                ['key' => 'deploy', 'label' => __('First deploy live'), 'done' => $build?->status === Build::STATUS_SUCCEEDED],
                ['key' => 'health', 'label' => __('Answering over HTTPS (Caddy gets the certificate once DNS points here)'), 'done' => $website?->health_status === 'up'],
            ],
        ];
    }
}
