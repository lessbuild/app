<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use Carbon\CarbonInterface;

final readonly class ServerTroubleshootingSessionViewData
{
    public function __construct(
        public string $id,
        public string $status,
        public string $expiresAt,
        public string $idleExpiresAt,
        public ?string $lastSeenAt,
        public ?string $connectedAt,
        public ?string $closedAt,
        public ?string $closeReason,
        public bool $canExecute,
        public int $inputSequence,
        public int $outputSequence,
    ) {}

    /** Build the safe metadata projection used by the HTTP and future Livewire boundary. */
    public static function fromSession(ServerTroubleshootingSession $session, bool $canExecute): self
    {
        return new self(
            (string) $session->public_id,
            (string) $session->status,
            self::timestamp($session->expires_at),
            self::timestamp($session->idle_expires_at),
            self::optionalTimestamp($session->last_seen_at),
            self::optionalTimestamp($session->connected_at),
            self::optionalTimestamp($session->closed_at),
            $session->close_reason,
            $canExecute,
            (int) $session->input_sequence,
            (int) $session->output_sequence,
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'expires_at' => $this->expiresAt,
            'idle_expires_at' => $this->idleExpiresAt,
            'last_seen_at' => $this->lastSeenAt,
            'connected_at' => $this->connectedAt,
            'closed_at' => $this->closedAt,
            'close_reason' => $this->closeReason,
            'can_execute' => $this->canExecute,
            'input_sequence' => $this->inputSequence,
            'output_sequence' => $this->outputSequence,
        ];
    }

    private static function timestamp(CarbonInterface $timestamp): string
    {
        return $timestamp->toIso8601String();
    }

    private static function optionalTimestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toIso8601String();
    }
}
