<?php

namespace App\Data;

use App\Models\ServerTroubleshootingSession;

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
