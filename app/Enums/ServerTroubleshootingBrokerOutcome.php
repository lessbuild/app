<?php

namespace App\Enums;

enum ServerTroubleshootingBrokerOutcome: string
{
    case NotClaimed = 'not_claimed';

    case Released = 'released';

    case Expired = 'expired';

    case Failed = 'failed';
}
