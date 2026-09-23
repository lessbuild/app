<?php

namespace App\Core\Services\Connections;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;

final class RetryProjectConnectionDeliveries
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(PlatformUser $user, Project $project, ProjectConnection $connection): int
    {
        if (! $this->access->canManageWorkspace($user, $project->workspace)
            || ! $this->access->canViewProject($user, $project)) {
            throw new AuthorizationException;
        }

        $connection = ProjectConnection::query()
            ->whereKey($connection->getKey())
            ->where('project_id', $project->getKey())
            ->whereNull('disconnected_at')
            ->firstOrFail();

        $retried = ProjectConnectionDelivery::query()
            ->where('project_connection_id', $connection->getKey())
            ->whereIn('status', ['failed', 'blocked'])
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'last_error_code' => null,
                'last_error_at' => null,
                'updated_at' => now(),
            ]);

        if ($retried > 0) {
            $connection->forceFill([
                'status' => 'pending',
                'last_error_code' => null,
                'last_error_at' => null,
            ])->save();
        }

        return $retried;
    }
}
