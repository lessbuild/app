<?php

namespace App\Core\Data\Projects;

enum ProjectProductSnapshotState: string
{
    case Current = 'current';
    case Attention = 'attention';
    case Empty = 'empty';
    case Unavailable = 'unavailable';
}
