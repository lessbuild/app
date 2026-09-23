<?php

namespace App\Core\Data\Projects;

enum ProjectSetupStepState: string
{
    case Complete = 'complete';
    case NeedsAction = 'needs_action';
    case Unavailable = 'unavailable';
}
