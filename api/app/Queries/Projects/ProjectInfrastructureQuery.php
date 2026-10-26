<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Project;
use App\Models\Server;
use App\Models\Website;
use App\Services\Projects\ProjectResources;
use Illuminate\Database\Eloquent\Collection;

/**
 * Works out the infrastructure a project owns: the websites that serve its environments or take its deploys, and the
 * servers only those websites run on. Deleting the project tears these down, so the settings page can warn about them
 * and the delete action can destroy them.
 */
final class ProjectInfrastructureQuery
{
    /**
     * Create a new ProjectInfrastructureQuery instance.
     *
     * @param  ProjectResources  $resources  Maps a project to its websites and servers.
     */
    public function __construct(private readonly ProjectResources $resources) {}

    /**
     * The project's websites: those serving its environments or taking one of its repositories' deploys.
     *
     * @param  Project  $project
     * @return Collection<int, Website>
     */
    public function websites(Project $project): Collection
    {
        return Website::query()->whereKey($this->resources->websiteIds([$project->id]))->get();
    }

    /**
     * The servers that only this project uses, so deleting it can destroy them. A server shared with another project
     * (it also hosts a website outside this project) is left alone.
     *
     * @param  Project  $project
     * @return Collection<int, Server>
     */
    public function exclusiveServers(Project $project): Collection
    {
        $websiteIds = $this->resources->websiteIds([$project->id]);
        $serverIds = $this->resources->serverIds([$project->id]);

        return Server::query()->whereKey($serverIds)->get()
            ->reject(fn (Server $server): bool => Website::query()->where('server_id', $server->id)->whereKeyNot($websiteIds)->exists())
            ->values();
    }
}
