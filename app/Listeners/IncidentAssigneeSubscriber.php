<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AccountRole;
use App\Events\Accounts\MemberRemoved;
use App\Events\Accounts\MemberRoleChanged;
use App\Events\Accounts\MemberServiceAccessChanged;
use App\Models\Membership;
use App\Services\Monitoring\IncidentLifecycle;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/** Unassigns incidents from people who left the account or can no longer work on Monitoring. */
final class IncidentAssigneeSubscriber
{
    public function __construct(private readonly IncidentLifecycle $incidents) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MemberRemoved::class => 'memberRemoved',
            MemberRoleChanged::class => 'accessChanged',
            MemberServiceAccessChanged::class => 'accessChanged',
        ];
    }

    public function memberRemoved(MemberRemoved $event): void
    {
        DB::transaction(fn () => $this->incidents->unassignMember($event->account, $event->member, $event->actor));
    }

    public function accessChanged(MemberRoleChanged|MemberServiceAccessChanged $event): void
    {
        $membership = $event->membership;
        if (! $this->canBeAssigned($membership)) {
            DB::transaction(fn () => $this->incidents->unassignMember($membership->account, $membership->user, $event->actor));
        }
    }

    private function canBeAssigned(Membership $membership): bool
    {
        return in_array($membership->role, [AccountRole::Owner, AccountRole::Admin, AccountRole::Member], true)
            && $membership->canUseService('monitoring');
    }
}
