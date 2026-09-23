<?php

namespace App\Modules\Deployer\Enums;

enum ServerTroubleshootingFrameDirection: string
{
    case Input = 'input';

    case Output = 'output';
}
