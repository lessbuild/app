<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Enums\ServerTroubleshootingBrokerOutcome;

final readonly class ServerTroubleshootingBrokerResult
{
    public function __construct(
        public ServerTroubleshootingBrokerOutcome $outcome,
        public int $cycles,
    ) {}
}
