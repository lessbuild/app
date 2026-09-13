<?php

namespace App\Data;

use App\Enums\ServerTroubleshootingFrameDirection;
use App\Models\ServerTroubleshootingFrame;

final readonly class ServerTroubleshootingFrameData
{
    public function __construct(
        public int $id,
        public ServerTroubleshootingFrameDirection $direction,
        public int $sequence,
        public string $payload,
        public int $bytes,
    ) {}

    /** Rehydrate only the bounded decrypted frame needed by a transport boundary. */
    public static function fromModel(ServerTroubleshootingFrame $frame): self
    {
        return new self(
            (int) $frame->id,
            $frame->direction instanceof ServerTroubleshootingFrameDirection
                ? $frame->direction
                : ServerTroubleshootingFrameDirection::from((string) $frame->direction),
            (int) $frame->sequence,
            (string) $frame->payload,
            (int) $frame->payload_bytes,
        );
    }
}
