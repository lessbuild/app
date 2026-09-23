<?php

namespace App\Modules\Deployer\Enums;

enum ServerDiagnosticFailureStage: string
{
    case ServerState = 'server_state';

    case HostIdentity = 'host_identity';

    case Transport = 'transport';

    case Response = 'response';
}
