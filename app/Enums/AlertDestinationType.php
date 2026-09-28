<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertDestinationType: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Teams = 'teams';
    case PagerDuty = 'pagerduty';
    case Discord = 'discord';

    /**
     * The destination type's name on the alert destination form.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Webhook => __('Signed webhook'),
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
            self::PagerDuty => 'PagerDuty',
            self::Discord => 'Discord',
        };
    }
}
