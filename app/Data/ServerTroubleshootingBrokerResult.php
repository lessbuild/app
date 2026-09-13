<?php

namespace App\Data;

use App\Enums\ServerTroubleshootingBrokerOutcome;

final readonly class ServerTroubleshootingBrokerResult
{
    public function __construct(
        public ServerTroubleshootingBrokerOutcome $outcome,
        public int $cycles,
    ) {}
}
