<?php

namespace App\Modules\Deployer\Enums;

enum DeploymentObservationStatus: string
{
    case Pending = 'pending';
    case Observing = 'observing';
    case Healthy = 'healthy';
    case Failed = 'failed';
    case Expired = 'expired';
    case Superseded = 'superseded';

    /** @var list<string> States whose observation work may still be claimed. */
    public const array ACTIVE_VALUES = [self::Pending->value, self::Observing->value];

    /** @var list<string> Terminal observation outcomes. */
    public const array TERMINAL_VALUES = [
        self::Healthy->value,
        self::Failed->value,
        self::Expired->value,
        self::Superseded->value,
    ];

    /** Return whether this observation may still receive a probe. */
    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE_VALUES, true);
    }

    /** Return whether this observation can no longer receive a probe. */
    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_VALUES, true);
    }
}
