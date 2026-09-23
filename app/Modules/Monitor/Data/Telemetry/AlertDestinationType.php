<?php

namespace App\Modules\Monitor\Data\Telemetry;

enum AlertDestinationType: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Teams = 'teams';
    case PagerDuty = 'pagerduty';
    case Discord = 'discord';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Webhook => 'Signed webhook',
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
            self::PagerDuty => 'PagerDuty',
            self::Discord => 'Discord',
        };
    }
}
