<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum SelectionKind: string
{
    case Tier = 'tier';
    case AddOn = 'addon';
}
