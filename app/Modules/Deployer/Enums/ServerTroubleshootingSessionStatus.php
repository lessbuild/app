<?php

namespace App\Modules\Deployer\Enums;

enum ServerTroubleshootingSessionStatus: string
{
    case Connecting = 'connecting';

    case Connected = 'connected';

    case Closing = 'closing';

    case Closed = 'closed';

    case Expired = 'expired';

    case Revoked = 'revoked';

    case Failed = 'failed';

    /** @var list<string> States that may still accept lifecycle activity. */
    public const array ACTIVE_VALUES = [
        self::Connecting->value,
        self::Connected->value,
        self::Closing->value,
    ];

    /** @var list<string> States that cannot be resumed or reused. */
    public const array TERMINAL_VALUES = [
        self::Closed->value,
        self::Expired->value,
        self::Revoked->value,
        self::Failed->value,
    ];

    /** Return whether the session may still be owned by a transport. */
    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE_VALUES, true);
    }

    /** Return whether the session has reached a final lifecycle outcome. */
    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_VALUES, true);
    }

    /** Return whether input or a heartbeat may be accepted in this state. */
    public function acceptsActivity(): bool
    {
        return in_array($this, [self::Connecting, self::Connected], true);
    }
}
