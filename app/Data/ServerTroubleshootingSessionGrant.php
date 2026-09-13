<?php

namespace App\Data;

use App\Models\ServerTroubleshootingSession;
use Carbon\CarbonInterface;

final readonly class ServerTroubleshootingSessionGrant
{
    /**
     * Carry an opaque, one-time transport grant without persisting its
     * plaintext value or placing it in a queued job.
     */
    public function __construct(
        public ServerTroubleshootingSession $session,
        public string $token,
    ) {}

    /** Return the absolute expiry retained by the session record. */
    public function expiresAt(): CarbonInterface
    {
        return $this->session->expires_at;
    }
}
