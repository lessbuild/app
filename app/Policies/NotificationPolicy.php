<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationPolicy
{
    /** Allow a recipient to mark its own notification as read while concealing other records. */
    public function read(User $user, DatabaseNotification $notification): Response
    {
        return $this->ownershipResponse($user, $notification);
    }

    /** Allow a recipient to reopen its own notification while concealing other records. */
    public function unread(User $user, DatabaseNotification $notification): Response
    {
        return $this->ownershipResponse($user, $notification);
    }

    /** Allow a recipient to delete its own notification while concealing other records. */
    public function delete(User $user, DatabaseNotification $notification): Response
    {
        return $this->ownershipResponse($user, $notification);
    }

    /** Preserve the inbox's deliberate not-found behavior for foreign recipient or morph type records. */
    private function ownershipResponse(User $user, DatabaseNotification $notification): Response
    {
        if ((string) $notification->notifiable_id === (string) $user->getKey()
            && $notification->notifiable_type === $user->getMorphClass()) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
