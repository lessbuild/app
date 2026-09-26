<?php

namespace App\Core\Services\Projects;

use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Enums\ProjectConnectionEventType;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionEvent;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetProjectConnectionAutomationState
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(
        PlatformUser $user,
        Project $project,
        ProjectConnection $connection,
        bool $paused,
    ): ProjectConnection {
        return DB::connection('core')->transaction(function () use ($user, $project, $connection, $paused): ProjectConnection {
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

            $supportsAutomation = collect((array) $connection->capabilities)
                ->contains(fn (string $capability): bool => ProjectConnectionCapability::tryFrom($capability)?->hasDeliveryHandler() ?? false);

            if (! $supportsAutomation) {
                throw ValidationException::withMessages([
                    'connection' => __('This read-only connection has no automation to pause.'),
                ]);
            }

            $isPaused = $connection->automation_paused_at !== null;

            if ($isPaused === $paused) {
                return $connection;
            }

            $eventType = $paused
                ? ProjectConnectionEventType::AutomationPaused
                : ProjectConnectionEventType::AutomationResumed;

            $connection->forceFill([
                'automation_paused_at' => $paused ? now() : null,
            ])->save();
            $connection = $connection->refresh();

            ProjectConnectionEvent::query()->create([
                'project_connection_id' => $connection->getKey(),
                'actor_user_id' => $user->getKey(),
                'event_type' => $eventType->value,
                'details' => null,
                'occurred_at' => now(),
            ]);

            return $connection;
        });
    }
}
