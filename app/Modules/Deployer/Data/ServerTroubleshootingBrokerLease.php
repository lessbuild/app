<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\ServerTroubleshootingSession;

final readonly class ServerTroubleshootingBrokerLease
{
    /** Carry the plaintext lease only in the in-memory broker boundary. */
    public function __construct(
        public ServerTroubleshootingSession $session,
        public string $token,
        public int $attempt,
        public int $processId,
    ) {}
}
