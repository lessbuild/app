<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Events\Accounts\MemberServiceAccessChanged;
use App\Exceptions\AccountRuleViolation;
use App\Models\Membership;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Support\Facades\Gate;

final class SetServiceAccess
{
    /**
     * Limits which services a member may use.
     *
     * @param  ServiceRegistry  $services  The service keys a membership may be limited to.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Sets the services a member may use (null for all of them). Owners and admins always have every service, unknown
     * keys are dropped, and nothing is recorded when the list doesn't change.
     *
     * @param  list<string>|null  $services  null gives access to every service, including ones added later
     */
    public function handle(User $actor, Membership $membership, ?array $services): Membership
    {
        Gate::forUser($actor)->authorize('manageMembers', $membership->account);
        if (! ($membership->account->roleOf($actor)?->canAssign($membership->role) ?? false)) {
            throw AccountRuleViolation::cannotAssign();
        }
        if (in_array($membership->role, [AccountRole::Owner, AccountRole::Admin], true) && $services !== null) {
            throw new AccountRuleViolation('services', __('Owners and administrators always have every service.'));
        }

        if ($services !== null) {
            $services = array_values(array_intersect($this->services->keys(), $services));
        }
        $membership->forceFill(['service_access' => $services]);
        if ($membership->isDirty('service_access')) {
            $membership->save();
            MemberServiceAccessChanged::dispatch($membership, $actor);
        }

        return $membership;
    }
}
