<?php

namespace App\Core\Enums;

enum WorkspaceRolloutOutcome: string
{
    case Exposed = 'exposed';
    case Completed = 'completed';
    case Degraded = 'degraded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Held = 'held';
}
