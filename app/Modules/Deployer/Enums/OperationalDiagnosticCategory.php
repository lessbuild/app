<?php

namespace App\Modules\Deployer\Enums;

enum OperationalDiagnosticCategory: string
{
    case Runtime = 'runtime';

    case Storage = 'storage';

    case Connectivity = 'connectivity';

    case Process = 'process';
}
