<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Infrastructure\DeleteServer;
use App\Actions\Infrastructure\DeleteWebsite;
use App\Events\Projects\ProjectDeleted;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectInfrastructureQuery;
use Illuminate\Support\Facades\Gate;

final class DeleteProject
{
    /**
     * Create a new DeleteProject instance.
     *
     * @param  ProjectInfrastructureQuery  $infrastructure  The servers and websites the project owns.
     * @param  DeleteServer  $deleteServer  Destroys a server at its provider, then the record.
     * @param  DeleteWebsite  $deleteWebsite  Removes a website and cleans it off its server.
     */
    public function __construct(
        private readonly ProjectInfrastructureQuery $infrastructure,
        private readonly DeleteServer $deleteServer,
        private readonly DeleteWebsite $deleteWebsite,
    ) {}

    /**
     * Delete a project with its environments and enabled services. First tear down the infrastructure only this project
     * uses: destroy its servers at the provider, then its websites. The servers go first and a provider that refuses to
     * destroy one throws, so the project is never deleted while a server it owned is left running and still billing.
     * Services clean up their own data from ProjectDeleted. Provider calls run before any record is deleted, so a slow
     * cloud API never holds the database's write lock.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @return void
     */
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('delete', $project);

        $account = $project->account;
        $servers = $this->infrastructure->exclusiveServers($project);
        $websites = $this->infrastructure->websites($project);

        // Destroy the droplets first: if the provider refuses, this throws and nothing else is touched, so the project
        // and its still-running server stay rather than leaving an orphan that keeps billing.
        foreach ($servers as $server) {
            $this->deleteServer->handle($account, $actor, $server);
        }

        // Then the websites. Their server link was nulled when the server went, so no stale placement cleanup is queued.
        foreach ($websites as $website) {
            $this->deleteWebsite->handle($account, $actor, $website);
        }

        $project->delete();
        ProjectDeleted::dispatch($project->id, $project->account_id, $project->name, $actor);
    }
}
