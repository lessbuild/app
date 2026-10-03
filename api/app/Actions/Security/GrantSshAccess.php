<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\Security\SyncSshAccess;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;

final class GrantSshAccess
{
    /**
     * Create a new GrantSshAccess instance.
     *
     * @param  ProjectServersQuery  $servers  Checks the server belongs to the project.
     * @param  Entitlements  $entitlements  Checks the plan includes it.
     * @param  RecordAuditEntry  $audit  Records who gave whom access.
     */
    public function __construct(private readonly ProjectServersQuery $servers, private readonly Entitlements $entitlements, private readonly RecordAuditEntry $audit) {}

    /**
     * Give a member of the account SSH access to one of the project's servers, as its deploy user, with the keys on
     * their profile (now and as they change them).
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  int  $serverId
     * @param  string  $userId
     * @return ServerSshGrant
     */
    public function handle(User $actor, Project $project, int $serverId, string $userId): ServerSshGrant
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        if (! $this->entitlements->for($project->account)->has('security.servers')) {
            throw new AccountRuleViolation('user_id', __('Team SSH keys come with the Pro Security plan and above.'));
        }
        $server = $this->servers->handle($project)->firstWhere('id', $serverId) ?? throw new AccountRuleViolation('user_id', __('That server isn’t part of this project.'));
        $member = Membership::query()->where('account_id', $project->account_id)->where('user_id', $userId)->with('user')->first()
            ?? throw new AccountRuleViolation('user_id', __('Only members of the account can be given access.'));
        $grant = ServerSshGrant::query()->where('server_id', $server->id)->where('user_id', $userId)->first() ?? new ServerSshGrant;
        $grant->forceFill(['server_id' => $server->id, 'user_id' => $userId, 'granted_by' => $actor->id, 'status' => 'pending', 'error' => null])->save();
        $this->audit->handle(AuditAction::SshAccessGranted, $actor, $project->account_id, ['member' => $member->user->name, 'server' => $server->name], $project->id);
        SyncSshAccess::dispatch($server->id, $userId)->afterCommit();

        return $grant;
    }
}
