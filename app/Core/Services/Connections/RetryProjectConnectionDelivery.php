<?php

namespace App\Core\Services\Connections;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RetryProjectConnectionDelivery
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(
        PlatformUser $user,
        Project $project,
        ProjectConnection $connection,
        ProjectConnectionDelivery $delivery,
    ): bool {
        return DB::connection('core')->transaction(function () use ($user, $project, $connection, $delivery): bool {
            $project = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->access->canManageWorkspace($user, $project->workspace)
                || ! $this->access->canViewProject($user, $project)) {
                throw new AuthorizationException;
            }

            $connection = ProjectConnection::query()
                ->whereKey($connection->getKey())
                ->where('project_id', $project->getKey())
                ->whereNull('disconnected_at')
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->automation_paused_at !== null) {
                return false;
            }

            $delivery = ProjectConnectionDelivery::query()
                ->whereKey($delivery->getKey())
                ->where('project_connection_id', $connection->getKey())
                ->whereIn('status', ['failed', 'blocked'])
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return false;
            }

            $delivery->forceFill([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'last_error_code' => null,
                'last_error_at' => null,
            ])->save();

            $connection->forceFill([
                'status' => 'pending',
                'last_error_code' => null,
                'last_error_at' => null,
            ])->save();

            return true;
        });
    }
}
