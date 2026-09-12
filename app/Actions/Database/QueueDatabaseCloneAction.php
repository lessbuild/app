<?php

namespace App\Actions\Database;

use App\Data\DatabaseCloneResult;
use App\Exceptions\DatabaseCloneException;
use App\Jobs\Database\CloneDatabaseJob;
use App\Models\DatabaseClone;
use App\Models\EnvironmentResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class QueueDatabaseCloneAction
{
    /**
     * Validate clone safety, persist the request, and queue the remote transfer.
     *
     * @throws AuthorizationException If source and target belong to different workspaces.
     * @throws DatabaseCloneException If source and target are incompatible or the target is production.
     */
    public function handle(
        EnvironmentResource $source,
        EnvironmentResource $target,
        User $actor,
        string $confirmation,
    ): DatabaseCloneResult {
        $sourceOrganizationId = $source->environment->project->organization_id;
        $targetOrganizationId = $target->environment->project->organization_id;
        if ((int) $sourceOrganizationId !== (int) $targetOrganizationId) {
            throw new AuthorizationException;
        }
        if ($source->type !== $target->type || $target->id === $source->id) {
            throw new DatabaseCloneException('Choose another database of the same type.');
        }
        if ($target->environment->type === 'production') {
            throw new DatabaseCloneException('Production databases cannot be clone targets.');
        }
        if (! hash_equals($target->name, $confirmation)) {
            return new DatabaseCloneResult(DatabaseCloneResult::CONFIRMATION_MISMATCH);
        }

        $clone = DatabaseClone::query()->create([
            'source_resource_id' => $source->id,
            'target_resource_id' => $target->id,
            'requested_by' => $actor->id,
            'status' => 'queued',
        ]);
        CloneDatabaseJob::dispatch($clone->id);

        return new DatabaseCloneResult(DatabaseCloneResult::QUEUED, $clone);
    }
}
