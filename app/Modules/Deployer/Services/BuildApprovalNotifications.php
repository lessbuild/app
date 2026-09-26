<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Notifications\NotificationInbox;
use Illuminate\Notifications\DatabaseNotification;

class BuildApprovalNotifications
{
    /**
     * Mark unread informational approval notifications for a build as read after a review decision.
     *
     * @param  Build  $build  Build whose pending approval notifications should be acknowledged.
     */
    public function acknowledge(Build $build): void
    {
        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('data->category', 'deployment')
            ->where('data->resource_id', $build->id)
            ->where('data->status', NotificationInbox::STATUS_INFO)
            ->update(['read_at' => now()]);
    }
}
