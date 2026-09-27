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
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MemberRoleChanged::class => 'roleChanged',
            MemberRemoved::class => 'memberRemoved',
            InvitationAccepted::class => 'invitationAccepted',
        ];
    }

    public function roleChanged(MemberRoleChanged $event): void
    {
        $member = $event->membership->user;
        if (! $member->is($event->actor)) {
            $account = $event->membership->account;
            $member->notify(new RoleChanged($account->id, $account->name, $event->to->label(), $event->actor->name));
        }
    }

    public function memberRemoved(MemberRemoved $event): void
    {
        if (! $event->member->is($event->actor)) {
            $event->member->notify(new RemovedFromAccount($event->account->name, $event->actor->name));
        }
    }

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
