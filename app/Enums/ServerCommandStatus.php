<?php

namespace App\Enums;

enum ServerCommandStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /** @var list<string> States whose remote work may still run. */
    public const array ACTIVE_VALUES = [self::Queued->value, self::Running->value];

    /** @var list<string> Completed outcomes eligible for rerunning. */
    public const array TERMINAL_VALUES = [self::Succeeded->value, self::Failed->value, self::Canceled->value];

    /** Return whether this command is queued or executing. */
    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE_VALUES, true);
    }

    /** Return whether this command has completed and may be rerun. */
    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_VALUES, true);
    }
}
