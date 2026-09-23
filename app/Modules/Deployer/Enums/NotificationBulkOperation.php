<?php

namespace App\Modules\Deployer\Enums;

enum NotificationBulkOperation: string
{
    case Read = 'read';

    case Unread = 'unread';

    case Delete = 'delete';
}
