<?php

declare(strict_types=1);

namespace App\Enums;

enum SelectionKind: string
{
    case Tier = 'tier';
    case AddOn = 'addon';
    case Usage = 'usage';
}
