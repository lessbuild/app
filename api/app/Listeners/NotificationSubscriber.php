<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Accounts\InvitationAccepted;
use App\Events\Accounts\MemberRemoved;
use App\Events\Accounts\MemberRoleChanged;
use App\Notifications\InvitationAccepted as InvitationAcceptedNotification;
use App\Notifications\RemovedFromAccount;
use App\Notifications\RoleChanged;
use Illuminate\Events\Dispatcher;

/** Tells people about account changes that affect them, but never about their own actions. */
final class NotificationSubscriber
{
    /**
     * Register the account events that can affect someone other than the person acting.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MemberRoleChanged::class => 'roleChanged',
            MemberRemoved::class => 'memberRemoved',
            InvitationAccepted::class => 'invitationAccepted',
        ];
    }

    /**
     * Tell a member their role changed, unless they changed it themselves.
     *
     * @param  MemberRoleChanged  $event
     * @return void
     */
    public function roleChanged(MemberRoleChanged $event): void
    {
        $member = $event->membership->user;
        if (! $member->is($event->actor)) {
            $account = $event->membership->account;
            $member->notify(new RoleChanged($account->id, $account->name, $event->to->label(), $event->actor->name));
        }
    }

    /**
     * Tell a person they were removed from an account; nothing is sent when they left on their own.
     *
     * @param  MemberRemoved  $event
     * @return void
     */
    public function memberRemoved(MemberRemoved $event): void
    {
        if (! $event->member->is($event->actor)) {
            $event->member->notify(new RemovedFromAccount($event->account->name, $event->actor->name));
        }
    }

    /**
     * Tell whoever sent an invitation that it was accepted, if they're still around and didn't accept it themselves.
     *
     * @param  InvitationAccepted  $event
     * @return void
     */
    public function invitationAccepted(InvitationAccepted $event): void
    {
        $inviter = $event->invitation->invitedBy;
        $member = $event->membership->user;
        if ($inviter !== null && ! $inviter->is($member)) {
            $account = $event->membership->account;
            $inviter->notify(new InvitationAcceptedNotification($account->id, $account->name, $member->name));
        }
    }
}
