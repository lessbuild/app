<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role is deliberately not mass-assignable; it changes only through account actions.
 *
 * @property string $id
 * @property string $account_id
 * @property string $user_id
 * @property AccountRole $role
 * @property list<string>|null $service_access null means every service
 * @property list<string>|null $project_ids the projects the member can see; null means every project
 * @property bool $deploy_protected may deploy to and change protected environments
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Account $account
 * @property-read User $user
 */
class Membership extends Model
{
    use HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * Reads `role` as an AccountRole, and `service_access` and `project_ids` as JSON lists (null means all).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => AccountRole::class, 'service_access' => 'array', 'project_ids' => 'array', 'deploy_protected' => 'boolean'];
    }

    /**
     * Determine whether the member can be assigned incidents and issues: they work on projects (an owner, admin or
     * member, not billing or viewer) and may use Monitoring.
     *
     * @return bool
     */
    public function canTakeMonitoringAssignments(): bool
    {
        return in_array($this->role, [AccountRole::Owner, AccountRole::Admin, AccountRole::Member], true) && $this->canUseService('monitoring');
    }

    /**
     * Determine whether the member may use a service. Owners and admins always reach every service; others may be
     * limited to a list.
     *
     * @param  string  $service
     * @return bool
     */
    public function canUseService(string $service): bool
    {
        return in_array($this->role, [AccountRole::Owner, AccountRole::Admin], true)
            || $this->service_access === null
            || in_array($service, $this->service_access, true);
    }

    /**
     * Get the account the membership is in.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the member.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine whether the member's role grants the permission.
     *
     * @param  AccountPermission  $permission
     * @return bool
     */
    public function allows(AccountPermission $permission): bool
    {
        return $this->role->allows($permission);
    }

    /**
     * Determine whether the member can see a project: owners and administrators see every project, and so does anyone
     * not limited to some.
     *
     * @param  string  $projectId
     * @return bool
     */
    public function canSeeProject(string $projectId): bool
    {
        return in_array($this->role, [AccountRole::Owner, AccountRole::Admin], true)
            || $this->project_ids === null
            || in_array($projectId, $this->project_ids, true);
    }

    /**
     * Determine whether the member may deploy to and change protected environments: owners and administrators always
     * may; others when they've been allowed.
     *
     * @return bool
     */
    public function canDeployProtected(): bool
    {
        return in_array($this->role, [AccountRole::Owner, AccountRole::Admin], true) || $this->deploy_protected;
    }
}
