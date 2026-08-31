<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\FailureNotification;
use App\Notifications\NotificationInbox;

class IncidentNotifier
{
    public function fail(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): void {
        $user->notify(new FailureNotification(
            $category,
            $resourceId,
            $title,
            $message,
        ));
    }

    public function recover(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): int {
        $resolved = $this->resolveFailures($user, $category, $resourceId);

        $this->recovery($user, $category, $resourceId, $title, $message);

        return $resolved;
    }

    public function recoverIfOpen(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): int {
        $resolved = $this->resolveFailures($user, $category, $resourceId);
        if ($resolved > 0) {
            $this->recovery($user, $category, $resourceId, $title, $message);
        }

        return $resolved;
    }

    private function resolveFailures(User $user, string $category, int $resourceId): int
    {
        return $user->unreadNotifications()
            ->where('data->category', $category)
            ->where('data->resource_id', $resourceId)
            ->where('data->status', NotificationInbox::STATUS_FAILED)
            ->update(['read_at' => now()]);
    }

    private function recovery(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): void {
        $user->notify(new FailureNotification(
            $category,
            $resourceId,
            $title,
            $message,
            NotificationInbox::STATUS_HEALTHY,
        ));

    }
}
