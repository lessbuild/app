<?php

namespace App\Core\Services\Projects;

use App\Core\Enums\ProjectConnectionEventType;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionEvent;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DisconnectProjectConnection
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(PlatformUser $user, Project $project, ProjectConnection $connection): ProjectConnection
    {
        return DB::connection('core')->transaction(function () use ($user, $project, $connection): ProjectConnection {
            $project = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->access->canManageWorkspace($user, $project->workspace)
                || ! $this->access->canViewProject($user, $project)) {
                throw new AuthorizationException;
            }

            $connection = ProjectConnection::query()
                ->whereKey($connection->getKey())
                ->where('project_id', $project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status === 'disconnected') {
                return $connection;
            }

            $connection->forceFill([
                'status' => 'disconnected',
                'disconnected_at' => now(),
            ])->save();

            $connection = $connection->refresh();

            ProjectConnectionEvent::query()->create([
                'project_connection_id' => $connection->getKey(),
                'actor_user_id' => $user->getKey(),
                'event_type' => ProjectConnectionEventType::Disconnected->value,
                'details' => null,
                'occurred_at' => now(),
            ]);

            return $connection;
        });
    }
}
