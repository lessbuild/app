<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ToolMove;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowToolMovesController
{
    /**
     * Show what was read from Laravel Forge or Ploi (never the token): each server's sites with their repository, PHP
     * version, cron jobs, daemons and deploy script, and which have been moved here already.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $moves = ToolMove::query()->where('account_id', $project->account_id)->orderBy('source')->get();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sources' => ToolMove::SOURCES,
            'moves' => $moves->map(fn (ToolMove $move): array => [
                'id' => $move->id,
                'source' => $move->sourceName(),
                'fetchedAt' => $move->fetched_at?->toIso8601String(),
                'servers' => array_map(fn (array $source): array => [
                    'name' => $source['name'],
                    'ip' => $source['ip'],
                    'sites' => array_map(fn (array $site): array => [
                        'key' => $site['key'],
                        'domain' => $site['domain'],
                        'repository' => $site['repository'],
                        'branch' => $site['branch'],
                        'php' => $site['php'],
                        'crons' => count(array_filter($source['crons'], fn (array $cron): bool => str_contains($cron['command'], $site['root']))),
                        'daemons' => count(array_filter($source['daemons'], fn (array $daemon): bool => str_contains($daemon['command'], $site['root']) || str_starts_with((string) $daemon['directory'], $site['root']))),
                        'hasEnv' => $site['env'] !== '',
                        'deployScript' => $site['deploy_script'],
                        'movedWebsiteId' => ($move->moved ?? [])[$site['key']] ?? null,
                    ], $source['sites']),
                ], $move->inventory ?? []),
            ])->values(),
            'servers' => Server::query()->where('account_id', $project->account_id)->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get()
                ->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => $server->label()])->values(),
        ]);
    }
}
