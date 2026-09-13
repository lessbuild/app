<?php

namespace App\Enums;

enum OperationalDiagnosticCategory: string
{
    case Runtime = 'runtime';

    case Storage = 'storage';

    case Connectivity = 'connectivity';

    case Process = 'process';
}
