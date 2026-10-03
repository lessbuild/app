<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\Security\RunSecurityFix;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Security\HardeningScripts;
use Illuminate\Support\Facades\Gate;

final class ApplySecurityFix
{
    /**
     * Create a new ApplySecurityFix instance.
     *
     * @param  ProjectServersQuery  $servers  Checks the server belongs to the project.
     * @param  RecordAuditEntry  $audit  Records who changed the server.
     */
    public function __construct(private readonly ProjectServersQuery $servers, private readonly RecordAuditEntry $audit) {}

    /**
     * Queue the one-click fix a server finding names, on that server. The server is audited again afterwards, so the
     * finding clears once the fix has worked.
     *
     * @param  User  $actor
     * @param  SecurityFinding  $finding
     * @return void
     */
    public function handle(User $actor, SecurityFinding $finding): void
    {
        $project = $finding->project;
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        $action = $finding->data['fix_action'] ?? null;
        $server = $this->servers->handle($project)->firstWhere('id', $finding->data['server_id'] ?? null);
        if (! is_string($action) || ! array_key_exists($action, HardeningScripts::ACTIONS) || $server === null || $finding->status !== 'open') {
            throw new AccountRuleViolation('fix', __('This finding doesn’t have a fix that can be applied from here.'));
        }
        $finding->forceFill(['data' => [...($finding->data ?? []), 'fix_status' => 'running', 'fix_error' => null]])->save();
        $this->audit->handle(AuditAction::SecurityFixApplied, $actor, $project->account_id, ['fix' => HardeningScripts::ACTIONS[$action], 'server' => $server->name], $project->id);
        RunSecurityFix::dispatch($finding->id, $server->id, $action)->afterCommit();
    }
}
