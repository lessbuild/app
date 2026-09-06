<?php

namespace App\Enums;

enum BuildStatus: string
{
    case Queued = 'queued';
    case AwaitingApproval = 'awaiting_approval';
    case Rejected = 'rejected';
    case Deploying = 'deploying';
    case Running = 'running';
    case TimingOut = 'timing_out';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /** @var list<string> States that reserve a deployment target. */
    public const array ACTIVE_VALUES = [
        self::AwaitingApproval->value,
        self::Queued->value,
        self::Deploying->value,
        self::Running->value,
        self::TimingOut->value,
    ];

    /** @var list<string> Completed outcomes that may be retried or reported. */
    public const array TERMINAL_VALUES = [
        self::Succeeded->value,
        self::Failed->value,
        self::Canceled->value,
        self::Rejected->value,
    ];

    /** Return whether this state still reserves the deployment target. */
    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE_VALUES, true);
    }

    /** Return whether execution has reached a completed outcome. */
    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_VALUES, true);
    }
}
