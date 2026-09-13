<?php

namespace App\Enums;

enum ServerDiagnosticStatus: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Ready = 'ready';

    case Failed = 'failed';

    /** @var list<string> States whose diagnostic work may still run. */
    public const array ACTIVE_VALUES = [self::Queued->value, self::Running->value];

    /** @var list<string> States that contain a completed diagnostic outcome. */
    public const array TERMINAL_VALUES = [self::Ready->value, self::Failed->value];

    /** Return whether this snapshot may still be claimed or is being executed. */
    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE_VALUES, true);
    }

    /** Return whether this snapshot has a completed outcome. */
    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_VALUES, true);
    }
}
