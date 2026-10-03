<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Models\Project;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;

/** The servers a project runs on: those hosting the websites its environments deploy to. */
final class ProjectServersQuery
{
    /**
     * Get the project's active servers, by name.
     *
     * @param  Project  $project
     * @return Collection<int, Server>
     */
    public function handle(Project $project): Collection
    {
        return Server::query()
            ->whereIn('id', Website::query()->whereIn('environment_id', $project->environments()->select('id'))->whereNotNull('server_id')->select('server_id'))
            ->where('provisioning_status', Server::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }
}
