<?php

namespace App\Core\Data\Projects;

enum ProductProjectContextState: string
{
    case None = 'none';
    case Available = 'available';
    case Unavailable = 'unavailable';
}
