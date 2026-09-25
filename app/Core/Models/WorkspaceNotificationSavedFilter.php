<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;

class WorkspaceNotificationSavedFilter extends CoreModel
{
    protected $table = 'workspace_notification_saved_filters';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
