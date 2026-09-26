<?php

namespace App\Core\Enums;

enum ProjectLifecycleEventType: string
{
    case Updated = 'updated';
    case Archived = 'archived';
    case Restored = 'restored';
}
