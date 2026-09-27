<?php

declare(strict_types=1);

namespace App\Listeners;

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
    /**
     * Keeps incident assignments pointing at people who can still act on them.
     *
     * @param  IncidentLifecycle  $incidents  Unassigns people and records it on the incidents.
     */
    public function __construct(private readonly IncidentLifecycle $incidents) {}

    /**
     * The membership changes that can take away someone's access to incidents.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MemberRemoved::class => 'memberRemoved',
            MemberRoleChanged::class => 'accessChanged',
            MemberServiceAccessChanged::class => 'accessChanged',
        ];
    }

    /**
     * Unassigns every incident in the account from a member who left or was removed.
     */
    public function memberRemoved(MemberRemoved $event): void
    {
        DB::transaction(fn () => $this->incidents->unassignMember($event->account, $event->member, $event->actor));
    }

    /**
     * Unassigns a member whose new role or service list means they can no longer be assigned incidents.
     */
    public function accessChanged(MemberRoleChanged|MemberServiceAccessChanged $event): void
    {
        $membership = $event->membership;
        if (! $membership->canTakeMonitoringAssignments()) {
            DB::transaction(fn () => $this->incidents->unassignMember($membership->account, $membership->user, $event->actor));
        }
    }
}
