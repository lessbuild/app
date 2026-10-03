<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Exceptions\AccountRuleViolation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SetProjectAccess
{
    /**
     * Limit a member to some of the account's projects (null for every project, including new ones) and choose whether
     * they may deploy to and change protected environments. Owners and administrators always have every project and
     * may deploy everywhere.
     *
     * @param  User  $actor
     * @param  Membership  $membership
     * @param  list<string>|null  $projectIds
     * @param  bool  $deployProtected
     * @return Membership
     */
    public function handle(User $actor, Membership $membership, ?array $projectIds, bool $deployProtected): Membership
    {
        Gate::forUser($actor)->authorize('manageMembers', $membership->account);
        if (! ($membership->account->roleOf($actor)?->canAssign($membership->role) ?? false)) {
            throw AccountRuleViolation::cannotAssign();
        }
        if (in_array($membership->role, [AccountRole::Owner, AccountRole::Admin], true)) {
            throw new AccountRuleViolation('projects', __('Owners and administrators always have every project.'));
        }
        if ($projectIds !== null) {
            $projectIds = Project::query()->where('account_id', $membership->account_id)->whereIn('id', $projectIds)->pluck('id')->values()->all();
        }
        $membership->forceFill(['project_ids' => $projectIds, 'deploy_protected' => $deployProtected])->save();

        return $membership;
    }
}
