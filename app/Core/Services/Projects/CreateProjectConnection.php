<?php

namespace App\Core\Services\Projects;

use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Enums\ProjectConnectionEventType;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionEvent;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateProjectConnection
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    /**
     * @param  list<string>  $capabilities
     */
    public function handle(
        PlatformUser $user,
        Project $project,
        string $sourceResourceId,
        string $targetResourceId,
        array $capabilities,
    ): ProjectConnection {
        return DB::connection('core')->transaction(function () use (
            $user,
            $project,
            $sourceResourceId,
            $targetResourceId,
            $capabilities,
        ): ProjectConnection {
            $project = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->access->canManageWorkspace($user, $project->workspace)
                || ! $this->access->canViewProject($user, $project)) {
                throw new AuthorizationException;
            }

            $source = $this->activeResource($project, $sourceResourceId, 'source_resource_id');
            $target = $this->activeResource($project, $targetResourceId, 'target_resource_id');

            if ($source->product === $target->product) {
                throw ValidationException::withMessages([
                    'target_resource_id' => __('Choose a resource from a different application.'),
                ]);
            }

            foreach ([[$source, 'source_resource_id'], [$target, 'target_resource_id']] as [$resource, $field]) {
                if (! $this->access->canAccessProductResource($user, $project, $resource->product)) {
                    throw new AuthorizationException;
                }

                if ($resource->environment_id !== null && ! ProjectEnvironment::query()
                    ->whereKey($resource->environment_id)
                    ->where('project_id', $project->getKey())
                    ->exists()) {
                    throw ValidationException::withMessages([
                        $field => __('The selected application resource has an invalid project environment mapping.'),
                    ]);
                }
            }

            $supported = array_map(
                static fn (ProjectConnectionCapability $capability): string => $capability->value,
                ProjectConnectionCapability::supportedBetween($source->product, $target->product),
            );
            $selected = array_values(array_unique($capabilities));

            if ($supported === [] || $selected === [] || array_diff($selected, $supported) !== []) {
                throw ValidationException::withMessages([
                    'capabilities' => __('That behavior is not supported for the selected application direction.'),
                ]);
            }

            $existing = ProjectConnection::query()
                ->where('source_resource_id', $source->getKey())
                ->where('target_resource_id', $target->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->disconnected_at === null && $existing->status !== 'disconnected') {
                throw ValidationException::withMessages([
                    'target_resource_id' => __('These application resources already have a connection.'),
                ]);
            }

            $attributes = [
                'project_id' => $project->getKey(),
                'source_resource_id' => $source->getKey(),
                'target_resource_id' => $target->getKey(),
                'source_environment_id' => $source->environment_id,
                'target_environment_id' => $target->environment_id,
                'capabilities' => $selected,
                'status' => 'pending',
                'created_by_user_id' => $user->getKey(),
                'last_succeeded_at' => null,
                'last_error_code' => null,
                'last_error_at' => null,
                'disconnected_at' => null,
                'metadata' => null,
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $connection = $existing->refresh();
                $eventType = ProjectConnectionEventType::Reconnected;
            } else {
                $connection = ProjectConnection::query()->create($attributes);
                $eventType = ProjectConnectionEventType::Created;
            }

            ProjectConnectionEvent::query()->create([
                'project_connection_id' => $connection->getKey(),
                'actor_user_id' => $user->getKey(),
                'event_type' => $eventType->value,
                'details' => ['capabilities' => $selected],
                'occurred_at' => now(),
            ]);

            return $connection;
        });
    }

    private function activeResource(Project $project, string $resourceId, string $field): ProjectResource
    {
        $resource = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->whereKey($resourceId)
            ->first();

        if ($resource === null) {
            throw ValidationException::withMessages([
                $field => __('Choose an active resource from this project.'),
            ]);
        }

        return $resource;
    }
}
