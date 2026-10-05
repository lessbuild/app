<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;

final class ArchiveProject
{
    /**
     * Create a new ArchiveProject instance.
     *
     * @param  RecordAuditEntry  $audit  Notes who archived or restored it in the account's audit log.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Archive a project (it leaves the projects list, with its services, data and servers left exactly as they are),
     * or restore it to the list.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  bool  $archived
     * @return Project
     */
    public function handle(User $user, Project $project, bool $archived): Project
    {
        if ($archived === ($project->archived_at !== null)) {
            return $project;
        }
        $project->forceFill(['archived_at' => $archived ? now() : null])->save();
        $this->audit->handle($archived ? AuditAction::ProjectArchived : AuditAction::ProjectRestored, $user, $project->account_id, ['project' => $project->name], $project->id);

        return $project;
    }
}
