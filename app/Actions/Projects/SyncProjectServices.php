<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;

final class SyncProjectServices
{
    /**
     * Create a new SyncProjectServices instance.
     *
     * @param  EnableService  $enable  Turns services on.
     * @param  DisableService  $disable  Turns services off.
     */
    public function __construct(private readonly EnableService $enable, private readonly DisableService $disable) {}

    /**
     * Turn a project's services on and off so exactly the given ones are on, as infrastructure-as-code asks.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  list<string>  $services
     * @return void
     */
    public function handle(User $actor, Project $project, array $services): void
    {
        $current = $project->enabledServices()->pluck('service')->all();
        foreach (array_diff($services, $current) as $service) {
            $this->enable->handle($actor, $project, $service);
        }
        foreach (array_diff($current, $services) as $service) {
            $this->disable->handle($actor, $project, $service);
        }
    }
}
