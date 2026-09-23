<?php

namespace App\Core\Enums;

enum ProjectWorkflowStepState: string
{
    case Succeeded = 'succeeded';
    case Pending = 'pending';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => __('Succeeded'),
            self::Pending => __('Queued'),
            self::Processing => __('In progress'),
            self::Delivered => __('Delivered'),
            self::Blocked => __('Blocked'),
            self::Failed => __('Failed'),
            self::Discarded => __('Stopped'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Succeeded, self::Delivered => 'success',
            self::Pending, self::Processing => 'warning',
            self::Blocked, self::Failed => 'danger',
            self::Discarded => 'neutral',
        };
    }
}
