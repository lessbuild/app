<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Security\SyncSshAccess;
use App\Models\Project;
use App\Models\ServerSshGrant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RevokeSshAccess
{
    /**
     * Create a new RevokeSshAccess instance.
     *
     * @param  RecordAuditEntry  $audit  Records who removed access.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Take someone's SSH access to a server away: their keys are removed from it, then the grant goes.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  ServerSshGrant  $grant
     * @return void
     */
    public function handle(User $actor, Project $project, ServerSshGrant $grant): void
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        $grant->forceFill(['status' => 'removing'])->save();
        $this->audit->handle(AuditAction::SshAccessRevoked, $actor, $project->account_id, ['member' => $grant->user->name, 'server' => $grant->server->name], $project->id);
        SyncSshAccess::dispatch($grant->server_id, $grant->user_id)->afterCommit();
    }
}
