<?php

namespace App\Core\Data\Projects;

enum ProjectEnvironmentContextState: string
{
    case All = 'all';
    case Selected = 'selected';
    case Unavailable = 'unavailable';
}
