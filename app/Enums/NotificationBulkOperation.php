<?php

namespace App\Enums;

enum NotificationBulkOperation: string
{
    case Read = 'read';

    case Unread = 'unread';

    case Delete = 'delete';
}
